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

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;

/**
 * One contract call, in process, for a server-rendered page.
 *
 * The catalogue, course and verification pages call core use cases directly,
 * because their use cases are stable classes this adapter can name. The
 * notification pages deliberately do not: the preference centre, the
 * unsubscribe landing page and the delivery feedback intake are defined by
 * openapi.yaml as paths with frozen request and response shapes, and by
 * nothing else. Calling the contract rather than a class means this adapter
 * holds no opinion about which use case answers which path, the WordPress
 * pages answer from the identical shapes, and a route that changes hands
 * inside the core changes nothing here.
 *
 * It is transport and holds no rules: the kernel owns routing, authorization
 * and every status code, exactly as it does on the REST mount. It never
 * throws, because the caller is a page that has to render something.
 *
 * @since 1.0.0
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
            $kernel = KernelFactory::kernel();
        } catch (\Throwable $failure) {
            ComponentLog::error(sprintf('CoreRoute could not build the kernel: %s', $failure->getMessage()));

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
                // Joomla's own helper, which is what this host trusts: the
                // core reads no address from a header itself, and a guest the
                // adapter cannot place shares a coarse counter rather than
                // one an attacker can spoof.
                self::clientAddress()
            ));
        } catch (ApiProblem $problem) {
            $response = $problem->toResponse();
        } catch (\Throwable $failure) {
            ComponentLog::error(sprintf(
                'CoreRoute failure on %s %s: %s in %s:%d',
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
     * The caller's address as Joomla itself establishes it.
     *
     * The rule itself moved to Service\ClientAddress when the REST mount
     * needed the same answer for the organisation access log's ip_hash: one
     * fact, one implementation, so a page route and a REST route can never
     * place the same caller differently.
     *
     * @since 1.0.0
     */
    private static function clientAddress(): ?string
    {
        return ClientAddress::resolve();
    }
}
