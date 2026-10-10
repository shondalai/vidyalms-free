<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\Input\Input;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;

/**
 * The host endpoint a mail feedback report is posted to.
 *
 * The contract route is POST /v1/notifications/feedback/{channelKey}, and on
 * the REST mount it already answers. This endpoint exists beside it because
 * the sender is not a browser and not an API client: it is an ESP webhook, or
 * a forwarder emptying a feedback mailbox, which posts a raw
 * message/feedback-report body to a plain site URL and reads a status code.
 * Joomla's API application negotiates content types and expects an API token,
 * so pointing an ESP at it means asking a third party to speak Joomla. This
 * task is a plain site URL that speaks HTTP.
 *
 * It decides nothing. The raw body, its content type and the per-channel
 * shared secret go to the core route, and the core's answer is emitted
 * verbatim, so this endpoint and the REST mount cannot disagree about whether
 * a report was accepted.
 *
 * There is no session, no CSRF token and no capability check, and all three
 * absences are deliberate: the caller is a machine on the far side of an ESP,
 * and the authorization is the shared secret the core checks against
 * provider_settings. A request with no valid secret is answered by the core
 * without saying whether the channel exists.
 *
 * @since 1.0.0
 */
final class FeedbackIntake
{
    /**
     * The header the contract names. It is read here rather than left to the
     * REST mount because this endpoint bypasses that mount entirely.
     */
    public const SECRET_HEADER = 'x-vidyalms-feedback-secret';

    /**
     * A report bigger than this is not a report. RFC 5965 reports are a few
     * kilobytes; an ESP batch webhook is larger but bounded. The cap is here
     * rather than in the core because it is a transport limit, and it is
     * generous enough that no honest sender meets it.
     */
    private const MAX_BODY_BYTES = 1048576;

    public function __construct(
        private readonly CMSApplicationInterface $app,
        private readonly Input $input,
    ) {
    }

    public function serve(): void
    {
        (new JsonEmitter($this->app))->emit($this->run());
    }

    private function run(): ApiResponse
    {
        $method = strtoupper((string) $this->input->getMethod());

        if ($method !== 'POST') {
            // The core answers 405 for a wrong method on a contract path; this
            // endpoint is not on the contract, so it says the same thing in
            // the same shape rather than inventing a second vocabulary.
            return new ApiResponse(
                405,
                ['type' => 'about:blank', 'title' => 'Method Not Allowed', 'status' => 405],
                ['Content-Type' => 'application/problem+json', 'Allow' => 'POST']
            );
        }

        $channel = trim((string) $this->input->get('channel', '', 'cmd'));

        if ($channel === '' || preg_match('/^[a-z0-9_.-]{1,64}$/', $channel) !== 1) {
            return ApiProblem::notFound()->toResponse();
        }

        $raw = file_get_contents('php://input');
        $raw = \is_string($raw) ? $raw : '';

        if (\strlen($raw) > self::MAX_BODY_BYTES) {
            return ApiProblem::badRequest('The feedback report is larger than this site accepts.')->toResponse();
        }

        $contentType = trim((string) $this->input->server->get('CONTENT_TYPE', $this->input->server->get('HTTP_CONTENT_TYPE', '', 'RAW'), 'RAW'));
        $authorization = (string) $this->input->server->get('HTTP_AUTHORIZATION', '', 'RAW');
        if ($authorization === '' && $this->input->server->get('PHP_AUTH_USER', '', 'RAW') === 'vidyalms') {
            $authorization = 'Basic ' . base64_encode('vidyalms:' . (string) $this->input->server->get('PHP_AUTH_PW', '', 'RAW'));
        }
        $secret = \Shondalai\VidyaLMS\Core\Http\FeedbackCredentials::secret(
            (string) $this->input->server->get('HTTP_X_VIDYALMS_FEEDBACK_SECRET', '', 'RAW'), $authorization,
        );

        try {
            $kernel = KernelFactory::kernel();
        } catch (\Throwable $failure) {
            ComponentLog::error('Feedback intake could not build the kernel: ' . $failure->getMessage());

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
            ComponentLog::error(sprintf(
                'Feedback intake failure on channel %s: %s in %s:%d',
                $channel,
                $failure->getMessage(),
                $failure->getFile(),
                $failure->getLine()
            ));

            return ApiProblem::internal()->toResponse();
        }
    }
}
