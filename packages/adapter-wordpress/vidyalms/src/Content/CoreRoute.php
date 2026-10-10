<?php

declare(strict_types=1);

/**
 * VidyaLMS: one contract call, in process, for a server-rendered page.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Content;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\WordPress\Plugin;
use Shondalai\VidyaLMS\WordPress\Rest\ClientAddress;

defined('ABSPATH') || exit;

/**
 * The catalogue, course and verification pages call core use cases directly,
 * because their use cases are stable classes this plugin can name. The
 * notification pages deliberately do not: the preference centre, the
 * unsubscribe landing page and the delivery feedback intake are defined by
 * openapi.yaml as paths with frozen request and response shapes, and by
 * nothing else. Calling the contract rather than a class means this plugin
 * holds no opinion about which use case answers which path, the Joomla pages
 * answer from the identical shapes, and a route that changes hands inside the
 * core changes nothing here.
 *
 * It is transport and holds no rules: the kernel owns routing, authorization
 * and every status code, exactly as it does on the REST mount. It never
 * throws, because the caller is a page that has to render something.
 */
final class CoreRoute
{
    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param ?string               $idempotencyKey caller-owned replay key; never generated here
     */
    public static function call(
        string $method,
        string $path,
        Principal $principal,
        array $body = [],
        array $query = [],
        ?string $rawBody = null,
        ?string $contentType = null,
        array $headers = [],
        ?string $idempotencyKey = null,
    ): CoreRouteResult {
        try {
            $kernel = Plugin::kernel();
        } catch (\Throwable $failure) {
            self::log('could not build the kernel: ' . $failure->getMessage());

            return CoreRouteResult::unavailable();
        }

        try {
            $response = $kernel->apiKernel()->handle(new ApiRequest(
                strtoupper($method),
                $path,
                $principal,
                $query,
                $body,
                $idempotencyKey,
                [],
                $rawBody,
                $contentType,
                $headers,
                // WordPress's own REMOTE_ADDR after its proxy handling, and a
                // filter for a site behind one, so the core reads no address
                // from a header itself and a guest this host cannot place
                // shares a coarse counter rather than one an attacker can
                // spoof.
                self::clientAddress()
            ));
        } catch (ApiProblem $problem) {
            $response = $problem->toResponse();
        } catch (\Throwable $failure) {
            self::log(sprintf(
                'failure on %s %s: %s in %s:%d',
                $method,
                $path,
                $failure->getMessage(),
                $failure->getFile(),
                $failure->getLine()
            ));

            return CoreRouteResult::unavailable();
        }

        return CoreRouteResult::fromResponse($response);
    }

    /**
     * Filter: (?string $address): ?string, for a site behind a proxy.
     *
     * WordPress core reads REMOTE_ADDR and no forwarded header, and so does
     * this: a header the host did not validate is not an address. A site that
     * terminates TLS elsewhere hooks this rather than the core learning about
     * proxies.
     */
    public const ADDRESS_FILTER = ClientAddress::FILTER;

    /**
     * The rule itself moved to Rest\ClientAddress when the REST mount needed
     * the same answer for the organisation access log's ip_hash. The filter
     * name is the same one, so a site already hooking it is unaffected, and
     * this constant stays because it is published in the plugin's README.
     */
    private static function clientAddress(): ?string
    {
        return ClientAddress::resolve();
    }

    /**
     * The caller of a page is a visitor, so nothing about a failure reaches
     * them; it goes to the logger port, which offers it to the vidyalms_log
     * action and writes it to the PHP log only under WP_DEBUG.
     */
    private static function log(string $message): void
    {
        try {
            Plugin::ports()->logger->error('vidyalms core-route ' . $message);
        } catch (\Throwable) {
            // A site so broken that it cannot build a logger is not made
            // better by a second throwable out of a page render.
        }
    }
}
