<?php
/**
 * @package     VidyaLMS
 * @subpackage  com_vidyalms
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Api\Controller;

use Joomla\CMS\Application\ApiApplication;
use Joomla\CMS\Log\Log;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Uri\Uri;
use Joomla\Input\Input;
use Joomla\Input\Json as JsonInput;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\RequestBody;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\ClientAddress;
use VidyaLMS\Component\VidyaLMS\Api\Service\KernelLocator;

// phpcs:disable PSR1.Files.SideEffects
defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The whole REST surface of com_vidyalms: one controller, one task.
 *
 * The response shape is defined by openapi.yaml and produced by the core
 * ApiKernel. This class only translates: Joomla request in, neutral
 * ApiRequest out, neutral ApiResponse in, Joomla response out. It adds no
 * routing, no authorization, no validation and no error mapping, because the
 * same kernel answers the WordPress mount and the two must stay identical.
 *
 * Adding a route in plg_webservices_vidyalms without adding it to openapi.yaml
 * and to the core kernel is a bug, not a feature.
 *
 * @since 1.0.0
 */
final class RestController extends BaseController
{
    /**
     * The mount stripped from the request path before the core sees it, so
     * "v1/vidyalms/courses/42/curriculum" becomes "/courses/42/curriculum".
     */
    private const MOUNT = 'v1/vidyalms';

    private const LOG_CATEGORY = 'com_vidyalms';

    /**
     * Server keys the api-authentication plugins read. A public route is never
     * authenticated by the API application, so this is how a public endpoint
     * learns that the caller did present credentials after all.
     *
     * @var  list<string>
     */
    private const CREDENTIAL_KEYS = [
        'HTTP_AUTHORIZATION',
        'REDIRECT_HTTP_AUTHORIZATION',
        'HTTP_X_JOOMLA_TOKEN',
        'PHP_AUTH_USER',
    ];

    private static bool $loggerRegistered = false;

    public function handle(): void
    {
        $app = $this->app;

        if (!$app instanceof ApiApplication) {
            throw new \RuntimeException('The VidyaLMS REST controller runs only in the Joomla API application.');
        }

        $this->emit($app, $this->run($app));
    }

    /**
     * Runs one request through the core kernel. Never throws: an escaped
     * throwable is a 500 problem with nothing of its own in the body.
     */
    private function run(ApiApplication $app): ApiResponse
    {
        try {
            return KernelLocator::kernel()->apiKernel()->handle($this->buildRequest($app));
        } catch (ApiProblem $problem) {
            return $problem->toResponse();
        } catch (\Throwable $failure) {
            $this->log(sprintf(
                'REST failure on %s %s: %s in %s:%d',
                $app->getInput()->getMethod(),
                $this->corePath(),
                $failure->getMessage(),
                $failure->getFile(),
                $failure->getLine()
            ));

            return ApiProblem::internal()->toResponse();
        }
    }

    /**
     * A 500 has to be recoverable from a log file even on a site that logs
     * nothing else, so the category carries its own destination.
     */
    private function log(string $message): void
    {
        if (!self::$loggerRegistered) {
            Log::addLogger(['text_file' => 'com_vidyalms.php'], Log::ALL, [self::LOG_CATEGORY]);
            self::$loggerRegistered = true;
        }

        Log::add($message, Log::ERROR, self::LOG_CATEGORY);
    }

    private function buildRequest(ApiApplication $app): ApiRequest
    {
        $input = $app->getInput();
        $body  = RequestBody::from($this->rawBody($input), $this->contentType($input));

        return new ApiRequest(
            strtoupper((string) $input->getMethod()),
            $this->corePath(),
            $this->principal($app),
            $this->queryParameters(),
            $body->decoded,
            $this->idempotencyKey($input),
            [],
            $body->raw,
            $body->contentType,
            $this->forwardedHeaders(),
            // Two core readers, one fact: the guest rate-limit bucket and the
            // salted ip_hash on an organisation access-log row. The core
            // never reads it from a header; the adapter supplies it from the
            // host's own answer, which honours the site's proxy setting.
            ClientAddress::resolve()
        );
    }

    /** @return array<string, string> Headers allowed by the shared API contract. */
    private function forwardedHeaders(): array
    {
        return ApiRequest::headersFor(self::requestHeaders());
    }

    /**
     * Every header the request carried, keyed by lowercased name.
     *
     * Read from $_SERVER rather than getallheaders(), which is not present on
     * every SAPI. The core applies the same allowlist on every route.
     *
     * @return  array<string, string>
     */
    private static function requestHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (!\is_string($key) || !str_starts_with($key, 'HTTP_') || !\is_scalar($value)) {
                continue;
            }

            $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = trim((string) $value);
        }

        return $headers;
    }

    /**
     * Resolves the caller.
     *
     * In the API application Factory::getUser() answers a guest whatever the
     * token says; the identity the application loaded during login is the only
     * correct source. Public routes skip that login entirely, so a public
     * request that carries credentials is authenticated here: the curriculum
     * endpoint is anonymous but merges the caller's own progress when there is
     * a caller.
     */
    private function principal(ApiApplication $app): Principal
    {
        $identity = $app->getIdentity();
        $userId   = $identity === null ? 0 : (int) $identity->id;

        if ($userId === 0 && $this->hasCredentials($app)) {
            try {
                $app->login(['username' => ''], ['silent' => true, 'action' => 'core.login.api']);
            } catch (\Throwable) {
                // Unusable credentials leave the caller a guest; a route that
                // needs a user gets its 401 from the core.
            }

            $identity = $app->getIdentity();
            $userId   = $identity === null ? 0 : (int) $identity->id;
        }

        return $userId > 0 ? Principal::user($userId) : Principal::guest();
    }

    private function hasCredentials(ApiApplication $app): bool
    {
        $server = $app->getInput()->server;

        foreach (self::CREDENTIAL_KEYS as $key) {
            if ((string) $server->get($key, '', 'RAW') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * The request path relative to the /v1 mount, with a leading slash.
     *
     * Mirrors ApiRouter::getRoutePath() minus its urldecode: percent-encoded
     * segments are decoded once, by the core router, and decoding here as well
     * would let an encoded slash split a segment.
     */
    private function corePath(): string
    {
        $path = Uri::getInstance()->getPath();

        try {
            $base = Uri::base(true);
        } catch (\RuntimeException) {
            $base = '';
        }

        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, \strlen($base));
        }

        $path = ltrim($path, '/');

        if ($path === 'index.php') {
            $path = '';
        } elseif (str_starts_with($path, 'index.php/')) {
            $path = substr($path, 10);
        }

        if ($path === self::MOUNT) {
            return '/';
        }

        if (str_starts_with($path, self::MOUNT . '/')) {
            $path = substr($path, \strlen(self::MOUNT) + 1);
        }

        return '/' . ltrim($path, '/');
    }

    /**
     * @return  array<string, string>
     */
    private function queryParameters(): array
    {
        $query = [];

        foreach ((array) Uri::getInstance()->getQuery(true) as $name => $value) {
            if (\is_scalar($value)) {
                $query[(string) $name] = (string) $value;
            }
        }

        return $query;
    }

    /**
     * The body as it arrived. What happens to it is RequestBody's decision and
     * is shared with the WordPress mount, so the two cannot answer the same
     * request differently.
     */
    private function rawBody(Input $input): ?string
    {
        $raw = $input instanceof JsonInput ? $input->getRaw() : file_get_contents('php://input');

        return \is_string($raw) ? $raw : null;
    }

    /**
     * The Content-Type of the request, from either name the SAPI uses.
     *
     * CGI and FastCGI are required to publish it as CONTENT_TYPE with no
     * HTTP_ prefix (RFC 3875 and every FPM build follows it), and mod_php
     * publishes HTTP_CONTENT_TYPE as well. Reading only the prefixed name
     * therefore answered null on PHP-FPM, and a null content type is decoded
     * as JSON: the RFC 5965 feedback report and the RFC 8058 one-click
     * unsubscribe were both answered "the request body is not valid JSON"
     * before the core saw them, and a gateway posting anything but JSON would
     * have been refused the same way. It is a transport fact read in one
     * place, so the two mounts cannot answer the same request differently.
     */
    private function contentType(Input $input): ?string
    {
        foreach (['HTTP_CONTENT_TYPE', 'CONTENT_TYPE'] as $name) {
            $header = trim((string) $input->server->get($name, '', 'RAW'));

            if ($header !== '') {
                return $header;
            }
        }

        return null;
    }

    private function idempotencyKey(Input $input): ?string
    {
        $key = trim((string) $input->server->get('HTTP_IDEMPOTENCY_KEY', '', 'RAW'));

        return $key === '' ? null : $key;
    }

    /**
     * Writes the neutral response onto the Joomla response.
     *
     * The body is echoed rather than set: ComponentHelper::renderComponent()
     * buffers this output into the document, and ApiApplication::render()
     * overwrites anything setBody() put there. Echoing is also what keeps the
     * core's exact JSON out of Joomla's JSON API envelope.
     */
    private function emit(ApiApplication $app, ApiResponse $response): void
    {
        $body = '';

        if ($response->status !== 204 && $response->data !== null) {
            $binary = self::binaryBody($response);

            if ($binary !== null) {
                $body = $binary;
            } else {
                $encoded = json_encode($response->data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

                if ($encoded === false) {
                    $this->log('REST response could not be encoded as JSON: ' . json_last_error_msg());

                    $response = ApiProblem::internal()->toResponse();
                    $encoded  = (string) json_encode($response->data, \JSON_UNESCAPED_SLASHES);
                }

                $body = $encoded;
            }
        }

        $app->setHeader('status', (string) $response->status, true);

        $hasContentType = false;

        foreach ($response->headers as $name => $value) {
            $app->setHeader($name, $value, true);

            if (strtolower($name) === 'content-type') {
                $hasContentType = true;
            }
        }

        if ($body === '') {
            return;
        }

        if (!$hasContentType) {
            $app->setHeader('Content-Type', 'application/json', true);
        }

        echo $body;
    }

    /**
     * The bytes of a binary response, or null when there are none.
     *
     * One of the free credential formats is a baked PNG, and `ApiResponse`
     * carries a decoded structure rather than a string, so a response holding
     * bytes says so on the object and hands them over whole.
     *
     * It used to be a sniff. The core wrote an inline literal,
     * `{"content": "<base64>", "encoding": "base64"}`, specified by a
     * comment, and each adapter guessed at recognising it: this method and
     * `CoreBodyEmitter` on the WordPress mount had to agree forever on a rule
     * written down nowhere, a third adapter would have invented a third
     * reading, and a drift between them does not fail a test, it serves
     * base64 text where a wallet expected a PNG. The core decides now, and
     * this asks.
     *
     * The base64 envelope is still on `$response->data`, so an internal
     * caller that reads the payload rather than the wire body sees exactly
     * what it saw before.
     *
     * @since 1.0.0
     */
    private static function binaryBody(ApiResponse $response): ?string
    {
        return $response->isBinary() ? $response->binaryBytes() : null;
    }
}
