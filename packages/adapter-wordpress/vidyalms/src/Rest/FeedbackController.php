<?php

declare(strict_types=1);

/**
 * VidyaLMS: the host endpoint a mail feedback report is posted to.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Rest;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * The contract route is POST /v1/notifications/feedback/{channelKey}, and on
 * the /v1 mount it already answers. This endpoint exists beside it because the
 * sender is not a browser and not an SDK client: it is an ESP webhook, or a
 * forwarder emptying a feedback mailbox, which posts a raw
 * message/feedback-report body and reads a status code.
 *
 * Two things make a separate mount worth having. The /v1 namespace is the
 * shared contract and its routes are addressed by a path parameter this plugin
 * must not special-case; and a machine posting an ARF report has no nonce, no
 * cookie and no Idempotency-Key, which is a shape the contract mount would
 * otherwise have to make an exception for. So this rides the plugin's own
 * namespace, vidyalms-host/v1, exactly as the upload endpoint does, and the
 * Joomla adapter mounts its own idiom under task=feedback.receive.
 *
 * It decides nothing. The raw body, its content type and the per-channel
 * shared secret go to the core route, and the core's answer is emitted
 * verbatim, so this endpoint and the /v1 mount cannot disagree about whether a
 * report was accepted.
 *
 * There is no session, no nonce and no capability check, and all three
 * absences are deliberate: the authorization is the shared secret the core
 * checks against provider_settings, and a request with no valid secret is
 * answered by the core without saying whether the channel exists.
 */
final class FeedbackController
{
    /**
     * The plugin's own namespace, deliberately not vidyalms/v1.
     */
    public const REST_NAMESPACE = 'vidyalms-host/v1';

    public const ROUTE = '/feedback/(?P<channel>[A-Za-z0-9_.-]{1,64})';
    public const TOKEN_ROUTE = self::ROUTE . '/(?P<feedback_key>[A-Za-z0-9_-]{32,128})';

    /**
     * The header the contract names, lowercased because that is how
     * ApiRequest::FORWARDED_HEADERS keys it.
     */
    public const SECRET_HEADER = 'x-vidyalms-feedback-secret';

    /**
     * A report bigger than this is not a report. RFC 5965 reports are a few
     * kilobytes; an ESP batch webhook is larger but bounded. The cap is here
     * rather than in the core because it is a transport limit, and it is
     * generous enough that no honest sender meets it.
     */
    private const MAX_BODY_BYTES = 1048576;

    private ?\Closure $kernelResolver;

    private CoreBodyEmitter $emitter;

    /**
     * @param (callable(): CoreKernel)|null $kernelResolver
     */
    public function __construct(?callable $kernelResolver = null)
    {
        $this->kernelResolver = $kernelResolver === null ? null : \Closure::fromCallable($kernelResolver);
        $this->emitter = new CoreBodyEmitter(function (string $message): void {
            $this->log('feedback ' . $message);
        });
    }

    /**
     * @param (callable(): CoreKernel)|null $kernelResolver
     */
    public static function boot(?callable $kernelResolver = null): self
    {
        $controller = new self($kernelResolver);

        add_action('rest_api_init', [$controller, 'registerRoutes']);

        return $controller;
    }

    public function registerRoutes(): void
    {
        foreach ([self::ROUTE, self::TOKEN_ROUTE] as $route) {
        register_rest_route(
            self::REST_NAMESPACE,
            $route,
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'handle'],
                    'permission_callback' => [$this, 'permitRequest'],
                ],
            ],
        );
        }
    }

    /** Machine callers use the same shared-secret check here and in the core handler. */
    public function permitRequest(WP_REST_Request $request): bool|\WP_Error
    {
        if (strlen((string) $request->get_body()) > self::MAX_BODY_BYTES) {
            return new \WP_Error('vidyalms_feedback_too_large', __('The feedback report is too large.', 'vidyalms'), ['status' => 413]);
        }
        $channel = $request->get_url_params()['channel'] ?? '';
        $secret = \Shondalai\VidyaLMS\Core\Http\FeedbackCredentials::secret(
            (string) $request->get_header(self::SECRET_HEADER), (string) $request->get_header('authorization'),
        );
        $secret = $this->withPathCredential($request, $secret);
        try {
            $kernel = $this->kernel();
            $ports = $kernel->ports();
            $limiter = new \Shondalai\VidyaLMS\Core\Http\Middleware\RateLimitMiddleware($ports->cache, $ports->clock, ['/feedback-auth']);
            $limiter->process(new ApiRequest('POST', '/feedback-auth', Principal::guest(), clientAddress: ClientAddress::resolve()),
                static fn (): ApiResponse => new ApiResponse(204, null));
            $kernel->recordDeliveryFeedback->authorize(
                \is_string($channel) ? $channel : '',
                trim($secret),
            );
            return true;
        } catch (ApiProblem $problem) {
            return new \WP_Error('vidyalms_feedback_rate_limited', __('Try again shortly.', 'vidyalms'), ['status' => $problem->status]);
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException) {
            return new \WP_Error('vidyalms_feedback_forbidden', __('Feedback authentication failed.', 'vidyalms'), ['status' => 401]);
        } catch (\Throwable $failure) {
            $this->log('permission failure: ' . $failure->getMessage());
            return new \WP_Error('vidyalms_feedback_unavailable', __('Feedback is unavailable.', 'vidyalms'), ['status' => 503]);
        }
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return $this->emitter->respond($request, $this->run($request));
    }

    private function run(WP_REST_Request $request): ApiResponse
    {
        $channel = $request->get_url_params()['channel'] ?? '';
        $channel = \is_string($channel) ? $channel : '';

        if (preg_match('/^[a-z0-9_.-]{1,64}$/', $channel) !== 1) {
            return ApiProblem::notFound()->toResponse();
        }

        $raw = $request->get_body();
        $raw = \is_string($raw) ? $raw : '';

        if (\strlen($raw) > self::MAX_BODY_BYTES) {
            return ApiProblem::badRequest('The feedback report is larger than this site accepts.')->toResponse();
        }

        $contentType = $request->get_header('content-type');
        $contentType = \is_string($contentType) ? trim($contentType) : '';

        $secret = \Shondalai\VidyaLMS\Core\Http\FeedbackCredentials::secret(
            (string) $request->get_header(self::SECRET_HEADER), (string) $request->get_header('authorization'),
        );
        $secret = $this->withPathCredential($request, $secret);
        $secret = trim($secret);

        try {
            $kernel = $this->kernel();
        } catch (\Throwable $failure) {
            $this->log('could not build the kernel: ' . $failure->getMessage());

            return ApiProblem::internal()->toResponse();
        }

        try {
            return $kernel->apiKernel()->handle(new ApiRequest(
                'POST',
                '/notifications/feedback/' . rawurlencode($channel),
                Principal::guest(),
                [],
                [],
                null,
                [],
                $raw === '' ? null : $raw,
                $contentType === '' ? null : $contentType,
                $secret === '' ? [] : [self::SECRET_HEADER => $secret],
                // An unauthenticated POST, so the guest rate-limit bucket is
                // the reader here rather than the access log; without it every
                // reporting mailbox provider shares one counter.
                ClientAddress::resolve()
            ));
        } catch (ApiProblem $problem) {
            return $problem->toResponse();
        } catch (\Throwable $failure) {
            $this->log(sprintf(
                'failure on channel %s: %s in %s:%d',
                $channel,
                $failure->getMessage(),
                $failure->getFile(),
                $failure->getLine()
            ));

            return ApiProblem::internal()->toResponse();
        }
    }

    private function kernel(): CoreKernel
    {
        if ($this->kernelResolver !== null) {
            $kernel = ($this->kernelResolver)();

            if ($kernel instanceof CoreKernel) {
                return $kernel;
            }
        }

        return vidyalms_kernel();
    }

    private function withPathCredential(WP_REST_Request $request, string $secret): string
    {
        $value = $request->get_url_params()['feedback_key'] ?? '';
        return $secret === '' && is_string($value) && preg_match('/^[A-Za-z0-9_-]{32,128}$/D', $value) === 1 ? $value : $secret;
    }

    private function log(string $message): void
    {
        if (!\defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        error_log('[vidyalms] ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}
