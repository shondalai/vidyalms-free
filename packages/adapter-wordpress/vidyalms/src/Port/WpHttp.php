<?php

declare(strict_types=1);

/**
 * VidyaLMS: the WordPress implementation of the core's outbound seam.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

defined('ABSPATH') || exit;

use Shondalai\VidyaLMS\Core\Port\Http\HttpPolicy;
use Shondalai\VidyaLMS\Core\Port\Http\HttpPort;
use Shondalai\VidyaLMS\Core\Port\Http\HttpRequest;
use Shondalai\VidyaLMS\Core\Port\Http\HttpResponse;
use Shondalai\VidyaLMS\WordPress\Setup\Settings;

/**
 * Every outbound call goes through wp_remote_request().
 *
 * That is not a convenience wrapper. WordPress's HTTP API is where a site's
 * own proxy constants (WP_PROXY_HOST and the rest), its CA bundle, its
 * `http_request_timeout` filter and every security plugin that inspects
 * outbound traffic already live. A gateway package bundling curl would be
 * invisible to all of it, which is exactly the failure the port exists to
 * prevent.
 *
 * **The allowlist fails closed and an empty list allows nothing.** A default
 * of "everything" would mean a site that never opened the settings screen is
 * a site any installed package may post to any host from. The site owner
 * names the hosts; `vidyalms_http_allowlist` lets a site that manages its
 * configuration in code name them there instead.
 *
 * A refusal is a response and never an exception, and a WP_Error is a
 * refusal: an exception out of a reconciliation pass would stop the pass
 * rather than recording one failed call.
 */
final class WpHttp implements HttpPort
{
    /**
     * The two timeout numbers, aliased from the core rather than redeclared.
     *
     * They were declared here and again in JoomlaHttp, which is how two
     * adapters come to disagree about a ceiling. The values live on HttpPolicy
     * beside the port; these stay so nothing referring to them by this class's
     * name breaks.
     */
    public const DEFAULT_TIMEOUT_SECONDS = HttpPolicy::DEFAULT_TIMEOUT_SECONDS;

    public const MAX_TIMEOUT_SECONDS = HttpPolicy::MAX_TIMEOUT_SECONDS;

    /** The filter a site managing its configuration in code names hosts through. */
    public const ALLOWLIST_FILTER = 'vidyalms_http_allowlist';

    public const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

    /**
     * @param list<string> $allowedHosts lowercase hosts; a leading dot means
     *                                   "this host and anything under it"
     */
    public function __construct(
        private readonly array $allowedHosts,
        private readonly int $timeoutCeilingSeconds = self::DEFAULT_TIMEOUT_SECONDS,
    ) {
    }

    /**
     * The port as this site is configured: the stored allowlist widened by the
     * filter, and the stored timeout ceiling.
     */
    public static function fromSettings(): self
    {
        $configured = self::parseAllowlist(Settings::httpAllowlist());

        /** @var mixed $filtered */
        $filtered = apply_filters('vidyalms_http_allowlist', $configured);
        $hosts = [];

        if (\is_array($filtered)) {
            foreach ($filtered as $entry) {
                if (\is_string($entry)) {
                    $hosts[] = strtolower(trim($entry));
                }
            }
        }

        return new self(array_values(array_unique(array_filter($hosts))), Settings::httpTimeoutSeconds());
    }

    public function request(HttpRequest $request): HttpResponse
    {
        $host = $request->host();

        if ($host === '' || !$this->allows($host)) {
            return HttpResponse::refused(HttpResponse::REFUSED_HOST_NOT_ALLOWED);
        }

        $started = microtime(true);

        $arguments = [
            'method' => $request->method,
            'timeout' => $this->timeoutFor($request),
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'limit_response_size' => self::MAX_RESPONSE_BYTES + 1,
            'headers' => $request->headers,
            // Bytes, never re-encoded: a signature is over bytes, and an
            // array here would make WordPress form-encode the body.
            'body' => $request->body,
            'sslverify' => true,
            'user-agent' => 'VidyaLMS/' . (\defined('VIDYALMS_VERSION') ? VIDYALMS_VERSION : '1.0.0'),
        ];

        /** @var mixed $response */
        $response = wp_remote_request($request->url, $arguments);

        if (is_wp_error($response)) {
            $code = (string) $response->get_error_code();

            // The code and the message together, classified by the one shared
            // rule: WordPress puts the word in the code and Joomla puts it in
            // the message, and two adapters sniffing two strings two ways is
            // how one failure comes to be called two things.
            return HttpResponse::refused(
                HttpPolicy::classify($code . ' ' . (string) $response->get_error_message()),
            );
        }

        $headers = [];
        $returned = wp_remote_retrieve_headers($response);

        if (\is_object($returned) && method_exists($returned, 'getAll')) {
            /** @var mixed $all */
            $all = $returned->getAll();

            if (\is_array($all)) {
                foreach ($all as $name => $value) {
                    $headers[(string) $name] = \is_array($value)
                        ? implode(', ', array_map('strval', $value))
                        : (string) $value;
                }
            }
        } elseif (\is_array($returned)) {
            foreach ($returned as $name => $value) {
                $headers[(string) $name] = \is_array($value)
                    ? implode(', ', array_map('strval', $value))
                    : (string) $value;
            }
        }

        $body = (string) wp_remote_retrieve_body($response);
        if (strlen($body) > self::MAX_RESPONSE_BYTES) {
            return HttpResponse::refused(HttpResponse::REFUSED_TRANSPORT);
        }

        return HttpResponse::of(
            (int) wp_remote_retrieve_response_code($response),
            $body,
            $headers,
            microtime(true) - $started,
        );
    }

    /**
     * An exact host, or a subdomain of an entry written with a leading dot.
     *
     * The rule itself lives on HttpPolicy, beside the port, because this
     * adapter and the Joomla one wrote the same loop twice and a third adapter
     * would have written it again. An allowlist rule spelled slightly
     * differently on one host is a host that is reachable on one CMS and not
     * on the other.
     */
    public function allows(string $host): bool
    {
        return HttpPolicy::allows($this->allowedHosts, $host);
    }

    /**
     * Parses the site's allowlist setting.
     *
     * One host per line or comma separated, because a site owner pasting from
     * a gateway's documentation will do either. A scheme, a port or a path
     * pasted in with the host is stripped rather than refused.
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
}
