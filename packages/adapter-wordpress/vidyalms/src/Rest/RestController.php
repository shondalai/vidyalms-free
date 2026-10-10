<?php

declare(strict_types=1);

/**
 * VidyaLMS: the WordPress mount of the core /v1 REST contract.
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
use Shondalai\VidyaLMS\Core\Http\RequestBody;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * The whole REST surface of the vidyalms plugin: one controller, one callback.
 *
 * The response shape is defined by openapi.yaml and produced by the core
 * ApiKernel. This class only translates: WordPress request in, neutral
 * ApiRequest out, neutral ApiResponse in, WordPress response out. It adds no
 * routing, no object authorization, no validation and no error mapping, because the
 * same kernel answers the Joomla mount and the two must stay identical.
 *
 * Adding a route here without adding it to openapi.yaml and to the core kernel
 * is a bug, not a feature. The fifteen routes of the contract are registered in
 * CoreKernel::apiKernel(); WordPress sees a single greedy catch-all so that
 * path matching, 404, 405, 401 and 403 all stay with the core. The Joomla
 * adapter proved the alternative wrong: registering the routes as protected let
 * the host answer unknown paths itself, with a 500 that leaked filesystem
 * paths. WordPress checks authentication before dispatch; the core still
 * checks capabilities and resource ownership on every request.
 *
 * Registering PATCH and DELETE as well as the three verbs the contract uses is
 * deliberate: it is what lets the core answer 405 on a known path with the
 * wrong verb instead of WordPress answering its own 404.
 *
 * The site's catalogue page size (Setup\Settings::cataloguePerPage) is
 * deliberately NOT applied here. It governs the server rendered catalogue: the
 * page, the block and the shortcode. GET /courses keeps the core's default, so
 * the same request answers with the same page size on WordPress and on Joomla,
 * which is what openapi.yaml documents and what the shared SDK and the mobile
 * app depend on. A caller who wants another size sends per_page.
 *
 * The site's self enrolment switch is not applied here either, and adding a
 * check for it would be a regression. It is an enrolment rule
 * (Provider\SiteSelfEnrolmentRule) installed on vidyalms_kernel_ready, so the
 * core refuses POST /enrolments with the 409 it already maps, and it refuses
 * the course page and every other caller with the same answer. A guard in this
 * class would cover only the routes this class owns and would be free to drift
 * from the rule.
 */
final class RestController
{
    /**
     * The REST namespace, so the contract is served at /wp-json/vidyalms/v1/...
     * exactly as openapi.yaml declares the WordPress server.
     */
    public const REST_NAMESPACE = 'vidyalms/v1';

    /**
     * The catch-all capture holding the path the core router owns.
     */
    private const PATH_PARAM = 'vidyalms_path';

    /**
     * WordPress routing plumbing that only exists on plain-permalink sites, so
     * it must not reach the core and change behaviour between two WordPress
     * installs, let alone between WordPress and Joomla.
     */
    private const WP_ROUTE_PARAM = 'rest_route';

    /** @var list<string> */
    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    private ?\Closure $kernelResolver;

    private CoreBodyEmitter $emitter;

    /**
     * The resolver is the plugin bootstrap's way to the one shared kernel: a
     * second kernel would mean a second event bus and a second idempotency
     * store. Left out, the controller falls back to the global vidyalms_kernel()
     * accessor at call time.
     *
     * @param (callable(): CoreKernel)|null $kernelResolver
     */
    public function __construct(?callable $kernelResolver = null)
    {
        $this->kernelResolver = $kernelResolver === null ? null : \Closure::fromCallable($kernelResolver);
        $this->emitter = new CoreBodyEmitter(function (string $message): void {
            $this->log('REST ' . $message);
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
        $handlers = [];

        foreach (self::METHODS as $method) {
            $handlers[] = [
                'methods' => $method,
                'callback' => [$this, 'handle'],
                'permission_callback' => [$this, 'permitRequest'],
            ];
        }

        register_rest_route(self::REST_NAMESPACE, '/(?P<' . self::PATH_PARAM . '>.*)', $handlers);
    }

    /** WordPress authenticates the session; the core still authorizes each object and action. */
    public function permitRequest(WP_REST_Request $request): bool|\WP_Error
    {
        if (get_current_user_id() > 0 || PublicRestRoutes::allows($request->get_method(), $this->corePath($request))) {
            return true;
        }
        return new \WP_Error('vidyalms_authentication_required', __('Sign in to use this endpoint.', 'vidyalms'), ['status' => 401]);
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return $this->emitter->respond($request, $this->run($request));
    }

    /**
     * Runs one request through the core kernel. Never throws: an escaped
     * throwable is a 500 problem with nothing of its own in the body.
     */
    private function run(WP_REST_Request $request): ApiResponse
    {
        try {
            return $this->kernel()->apiKernel()->handle($this->buildRequest($request));
        } catch (ApiProblem $problem) {
            return $problem->toResponse();
        } catch (\Throwable $failure) {
            $this->log(sprintf(
                'REST failure on %s %s: %s in %s:%d',
                $request->get_method(),
                $this->corePath($request),
                $failure->getMessage(),
                $failure->getFile(),
                $failure->getLine()
            ));

            return ApiProblem::internal()->toResponse();
        }
    }

    private function buildRequest(WP_REST_Request $request): ApiRequest
    {
        $body = RequestBody::from($this->rawBody($request), $this->contentType($request));

        return new ApiRequest(
            strtoupper($request->get_method()),
            $this->corePath($request),
            $this->principal(),
            $this->queryParameters($request),
            $body->decoded,
            $this->idempotencyKey($request),
            [],
            $body->raw,
            $body->contentType,
            $this->forwardedHeaders($request),
            // Two core readers, one fact: the guest rate-limit bucket and the
            // salted ip_hash on an organisation access-log row. The core
            // never reads it from a header; the adapter supplies it from its
            // own trusted source, which on WordPress is REMOTE_ADDR plus the
            // proxy filter.
            ClientAddress::resolve()
        );
    }

    /** @return array<string, string> Headers accepted by the core API contract. */
    private function forwardedHeaders(WP_REST_Request $request): array
    {
        $headers = [];

        foreach (array_keys($request->get_headers()) as $key) {
            if (!\is_string($key)) {
                continue;
            }

            $name  = strtolower(str_replace('_', '-', $key));
            $value = $request->get_header($name);
            $value = \is_string($value) ? trim($value) : '';

            if ($value !== '') {
                $headers[$name] = $value;
            }
        }

        return ApiRequest::headersFor($headers);
    }

    /**
     * Resolves the caller from whatever WordPress already authenticated: a
     * cookie plus nonce for the React apps, an application password for the
     * mobile app. The core never inspects either.
     */
    private function principal(): Principal
    {
        $userId = (int) get_current_user_id();

        return $userId > 0 ? Principal::user($userId) : Principal::guest();
    }

    /**
     * The request path relative to the /v1 mount, with a leading slash, so the
     * core sees "/courses/42/curriculum".
     *
     * Read from the URL params rather than get_param(): WordPress resolves
     * get_param() against the body and the query string before the URL match,
     * so a request carrying its own vidyalms_path member would otherwise choose
     * its own route.
     */
    private function corePath(WP_REST_Request $request): string
    {
        $params = $request->get_url_params();
        $path = $params[self::PATH_PARAM] ?? '';

        return '/' . ltrim(\is_string($path) ? $path : '', '/');
    }

    /**
     * @return array<string, string>
     */
    private function queryParameters(WP_REST_Request $request): array
    {
        $query = [];

        foreach ($request->get_query_params() as $name => $value) {
            $name = (string) $name;

            if ($name === self::WP_ROUTE_PARAM || !\is_scalar($value)) {
                continue;
            }

            $query[$name] = (string) $value;
        }

        return $query;
    }

    /** Preserve the original request body alongside its decoded representation. */
    private function rawBody(WP_REST_Request $request): string
    {
        return $request->get_body();
    }

    private function contentType(WP_REST_Request $request): ?string
    {
        $header = $request->get_header('content-type');
        $header = \is_string($header) ? trim($header) : '';

        return $header === '' ? null : $header;
    }

    private function idempotencyKey(WP_REST_Request $request): ?string
    {
        $key = $request->get_header('idempotency-key');
        $key = \is_string($key) ? trim($key) : '';

        return $key === '' ? null : $key;
    }

    private function kernel(): CoreKernel
    {
        if ($this->kernelResolver !== null) {
            $kernel = ($this->kernelResolver)();

            if ($kernel instanceof CoreKernel) {
                return $kernel;
            }

            throw new \RuntimeException('The VidyaLMS kernel resolver did not return a core kernel.');
        }

        if (function_exists('vidyalms_kernel')) {
            $kernel = vidyalms_kernel();

            if ($kernel instanceof CoreKernel) {
                return $kernel;
            }
        }

        throw new \RuntimeException('The VidyaLMS core kernel is not available on this request.');
    }

    /**
     * A 500 has to be recoverable from a debug log, and nothing about it may
     * reach the client. Silent unless the site owner asked for debugging.
     */
    private function log(string $message): void
    {
        if (!\defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        error_log('[vidyalms] ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}
