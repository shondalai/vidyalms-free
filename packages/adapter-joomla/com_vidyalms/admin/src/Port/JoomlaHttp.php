<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Joomla\Http\Http;
use Shondalai\VidyaLMS\Core\Port\Http\HttpPolicy;
use Shondalai\VidyaLMS\Core\Port\Http\HttpPort;
use Shondalai\VidyaLMS\Core\Port\Http\HttpRequest;
use Shondalai\VidyaLMS\Core\Port\Http\HttpResponse;

/**
 * The Joomla implementation of the core's one outbound seam.
 *
 * Joomla already owns every operational fact the port says belongs to the
 * adapter, and owns them in one place a site owner can see: the proxy lives in
 * Global Configuration, the CA bundle is whatever the site's transport was
 * built with, and the timeout ceiling and the outbound host allowlist are
 * component options. Nothing here is invented; this class carries the four
 * across the boundary so no gateway package has to.
 *
 * **The allowlist is the load-bearing one, and it fails closed.** An empty
 * list allows nothing. That is deliberate rather than convenient: a default
 * of "everything" would mean a site that has never opened the settings screen
 * is a site any installed package can post to any host from, and the day that
 * matters is the day a package is compromised. A site owner naming the two
 * gateway hosts they actually use is one text box; discovering after the fact
 * that a package exfiltrated to a host nobody named is not recoverable.
 *
 * A refusal is a response and never an exception, because the port says so:
 * an exception thrown out of a reconciliation pass stops the pass rather than
 * recording one failed call.
 *
 * @since 1.1.0
 */
final class JoomlaHttp implements HttpPort
{
    /**
     * The two timeout numbers, aliased from the core rather than redeclared.
     *
     * They were declared here and again in WpHttp, which is how two adapters
     * come to disagree about a ceiling. The values live on HttpPolicy beside
     * the port; these stay so nothing referring to them by this class's name
     * breaks.
     */
    public const DEFAULT_TIMEOUT_SECONDS = HttpPolicy::DEFAULT_TIMEOUT_SECONDS;

    public const MAX_TIMEOUT_SECONDS = HttpPolicy::MAX_TIMEOUT_SECONDS;

    /**
     * @param list<string> $allowedHosts lowercase hosts; a leading dot means
     *                                   "this host and anything under it"
     */
    public function __construct(
        private readonly Http $client,
        private readonly array $allowedHosts,
        private readonly int $timeoutCeilingSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $host = $request->host();

        if ($host === '' || !$this->allows($host)) {
            return HttpResponse::refused(HttpResponse::REFUSED_HOST_NOT_ALLOWED);
        }

        $timeout = $this->timeoutFor($request);
        $started = microtime(true);

        try {
            $response = $this->dispatch($request, $timeout);
        } catch (\Throwable $failure) {
            // Joomla's transports raise for a connection failure and for a
            // timeout alike, and the port's contract is that neither leaves
            // this method as an exception. The two are separated where the
            // message allows it, because "the site cannot reach this host at
            // all" and "the host is slow" send a site owner to different
            // places.
            return HttpResponse::refused(HttpPolicy::classify($failure));
        }

        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', array_map('strval', $values));
        }

        return HttpResponse::of(
            $response->getStatusCode(),
            (string) $response->getBody(),
            $headers,
            microtime(true) - $started,
        );
    }

    /**
     * An exact host, or a subdomain of an entry written with a leading dot.
     *
     * The rule itself lives on HttpPolicy, beside the port, because this
     * adapter and the WordPress one wrote the same loop twice and a third
     * adapter would have written it again. An allowlist rule spelled slightly
     * differently on one host is a host that is reachable on one CMS and not
     * on the other.
     */
    public function allows(string $host): bool
    {
        return HttpPolicy::allows($this->allowedHosts, $host);
    }

    /**
     * Parses the site's allowlist option.
     *
     * One host per line or comma separated, because a site owner pasting from
     * a gateway's documentation will do either. A scheme, a port or a path
     * pasted in with the host is stripped rather than refused, so
     * "https://api.example.com/v1" names the host it obviously means.
     *
     * @return list<string>
     */
    public static function parseAllowlist(string $configured): array
    {
        $hosts = [];

        foreach (preg_split('/[\s,;]+/', $configured) ?: [] as $entry) {
            $entry = strtolower(trim($entry));

            if ($entry === '') {
                continue;
            }

            $entry = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $entry);
            $entry = (string) preg_replace('#[/?].*$#', '', $entry);
            $entry = (string) preg_replace('#:\d+$#', '', $entry);

            if ($entry === '' || $entry === '.') {
                continue;
            }

            $hosts[$entry] = true;
        }

        return array_keys($hosts);
    }

    /**
     * The caller's preference, lowered to the site's ceiling and never raised
     * by it.
     */
    private function timeoutFor(HttpRequest $request): int
    {
        return HttpPolicy::timeoutFor($request->timeoutSeconds, $this->timeoutCeilingSeconds);
    }

    private function dispatch(HttpRequest $request, int $timeout): \Joomla\Http\Response
    {
        $headers = $request->headers;
        $body = $request->body ?? '';

        return match ($request->method) {
            'GET' => $this->client->get($request->url, $headers, $timeout),
            'HEAD' => $this->client->head($request->url, $headers, $timeout),
            'POST' => $this->client->post($request->url, $body, $headers, $timeout),
            'PUT' => $this->client->put($request->url, $body, $headers, $timeout),
            'PATCH' => $this->client->patch($request->url, $body, $headers, $timeout),
            'DELETE' => $this->client->delete($request->url, $headers, $timeout, $body),
            default => throw new \LogicException('HttpRequest::create refuses every other method.'),
        };
    }
}
