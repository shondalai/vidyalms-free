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
use Joomla\CMS\Session\Session;
use Joomla\Input\Input;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\RequestBody;

/**
 * One JSON passthrough of the /v1 contract, served from a CMS browser session.
 *
 * The Joomla API application authenticates with API tokens, never with the
 * browser session, so no logged-in page can call /api/index.php/v1/vidyalms
 * directly. This class serves the same core ApiKernel from whichever
 * application asks: the site session for the learner apps, the administrator
 * session for the instructor workbench and the admin screens. The two sessions
 * are different sessions with different form tokens, which is why each
 * application mounts its own task=api.route and both land here.
 *
 * Nothing is decided here. The contract path travels URL-encoded in the "path"
 * query parameter (the SDK's "query" URL mode), the caller is whichever
 * identity the application holds, and the core response is emitted verbatim:
 * routing, authorization, status codes and the body shape all belong to the
 * kernel, so both surfaces answer byte-identically to the API application and
 * to the WordPress mount.
 *
 * Every request must carry the session CSRF token in the X-CSRF-Token header
 * (the header Joomla's own Session::checkToken() reads); each mount config
 * hands its own application's token to the apps. Guests hold a session token
 * too, so public paths keep working for them and the core answers 401 where a
 * user is required.
 *
 * @since 1.0.0
 */
final class ApiPassthrough
{
    /**
     * The header every request must carry. Joomla's own
     * Session::checkToken() reads exactly this header, so the apps ride the
     * CMS convention instead of inventing one.
     */
    public const CSRF_HEADER = 'X-CSRF-Token';

    public function __construct(
        private readonly CMSApplicationInterface $app,
        private readonly Input $input,
    ) {
    }

    /**
     * Runs one request and writes the response, then closes the application:
     * no template, no document, no further rendering.
     */
    public function serve(): void
    {
        $response = $this->csrfTokenValid()
            ? $this->run()
            : ApiProblem::forbidden('The CSRF token is missing or invalid.')->toResponse();

        (new JsonEmitter($this->app))->emit($response);
    }

    /**
     * The header comparison is done here rather than through checkToken():
     * that helper answers an expired session with an HTML redirect, and a JSON
     * surface must answer JSON.
     */
    private function csrfTokenValid(): bool
    {
        $sent = (string) $this->input->server->get('HTTP_X_CSRF_TOKEN', '', 'alnum');

        return $sent !== '' && hash_equals(Session::getFormToken(), $sent);
    }

    /**
     * Runs one request through the core kernel. Never throws: an escaped
     * throwable is a 500 problem with nothing of its own in the body.
     */
    private function run(): ApiResponse
    {
        try {
            return KernelFactory::kernel()->apiKernel()->handle($this->buildRequest());
        } catch (ApiProblem $problem) {
            return $problem->toResponse();
        } catch (\Throwable $failure) {
            $this->log(sprintf(
                'API passthrough failure on %s %s: %s in %s:%d',
                $this->input->getMethod(),
                $this->corePath(),
                $failure->getMessage(),
                $failure->getFile(),
                $failure->getLine()
            ));

            return ApiProblem::internal()->toResponse();
        }
    }

    private function log(string $message): void
    {
        ComponentLog::error($message);
    }

    private function buildRequest(): ApiRequest
    {
        $body = RequestBody::from($this->rawBody(), $this->contentType());

        return new ApiRequest(
            strtoupper((string) $this->input->getMethod()),
            $this->corePath(),
            $this->principal(),
            $this->queryParameters(),
            $body->decoded,
            $this->idempotencyKey(),
            [],
            $body->raw,
            $body->contentType,
            $this->forwardedHeaders(),
            // The administrator mount answers the same organisation routes as
            // the REST mount, so it has to supply the same fact: without it
            // an access-log row written from wp-admin's Joomla counterpart
            // would carry no ip_hash while the identical read through /v1
            // carried one.
            ClientAddress::resolve()
        );
    }

    /** @return array<string, string> Headers allowed by the shared API contract. */
    private function forwardedHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!\is_string($key) || !str_starts_with($key, 'HTTP_') || !\is_scalar($value)) {
                continue;
            }

            $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = trim((string) $value);
        }

        return ApiRequest::headersFor($headers);
    }

    /**
     * The caller is whoever the session says: exactly the identity that
     * rendered the page carrying the mount.
     */
    private function principal(): Principal
    {
        $identity = $this->app->getIdentity();
        $userId   = $identity === null || $identity->guest ? 0 : (int) $identity->id;

        return $userId > 0 ? Principal::user($userId) : Principal::guest();
    }

    /**
     * The contract path from the "path" parameter, without its query string.
     * PHP already decoded the parameter once when it parsed the request URL,
     * so the value arrives as the literal path the SDK encoded, query
     * included: "/me/progress?course_id=3".
     */
    private function corePath(): string
    {
        $raw  = $this->rawPathParameter();
        $path = (string) (parse_url($raw, \PHP_URL_PATH) ?? '');

        return '/' . ltrim($path, '/');
    }

    /**
     * The contract path may carry its own query string; those pairs are the
     * request's query parameters, mirroring what the API application reads
     * from its request URL.
     *
     * @return  array<string, string>
     */
    private function queryParameters(): array
    {
        $raw   = $this->rawPathParameter();
        $inner = (string) (parse_url($raw, \PHP_URL_QUERY) ?? '');

        if ($inner === '') {
            return [];
        }

        parse_str($inner, $decoded);

        $query = [];

        foreach ($decoded as $name => $value) {
            if (\is_scalar($value)) {
                $query[(string) $name] = (string) $value;
            }
        }

        return $query;
    }

    private function rawPathParameter(): string
    {
        return trim((string) $this->input->get('path', '', 'raw'));
    }

    /**
     * The body as it arrived. What happens to it is RequestBody's decision and
     * is shared with the REST mount and with WordPress, so no two of the three
     * can answer the same request differently.
     */
    private function rawBody(): ?string
    {
        $raw = file_get_contents('php://input');

        return \is_string($raw) ? $raw : null;
    }

    /**
     * The Content-Type of the request, from either name the SAPI uses.
     *
     * CGI and FastCGI publish it as CONTENT_TYPE with no HTTP_ prefix
     * (RFC 3875), and mod_php publishes HTTP_CONTENT_TYPE as well. Reading
     * only the prefixed name answered null on PHP-FPM, and a null content
     * type is decoded as JSON, so every non-JSON body was refused as
     * malformed JSON before the core saw it.
     */
    private function contentType(): ?string
    {
        foreach (['HTTP_CONTENT_TYPE', 'CONTENT_TYPE'] as $name) {
            $header = trim((string) $this->input->server->get($name, '', 'RAW'));

            if ($header !== '') {
                return $header;
            }
        }

        return null;
    }

    private function idempotencyKey(): ?string
    {
        $key = trim((string) $this->input->server->get('HTTP_IDEMPOTENCY_KEY', '', 'RAW'));

        return $key === '' ? null : $key;
    }
}
