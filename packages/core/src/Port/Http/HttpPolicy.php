<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Http;

/**
 * The three decisions every HttpPort implementation has to make identically,
 * made once here.
 *
 * They were made twice, once per adapter, and that is a security surface
 * rather than duplication: `JoomlaHttp` and `WpHttp` each redeclared the two
 * timeout constants, each wrote the same host-match loop, each wrote the same
 * clamp, and each classified a transport failure onto `REFUSED_TIMEOUT` by
 * sniffing an error string. A Drupal adapter would have written all four a
 * third time, and the first one written slightly differently is the one where
 * an allowlist entry stops meaning what it means everywhere else.
 *
 * An adapter supplies transport and nothing else: the client, the proxy, the
 * CA bundle and the site's own host list and ceiling. What a host is allowed,
 * how long a call may run and what a failure is called are the port's
 * contract, and they live with the port.
 */
final class HttpPolicy
{
    /** What an adapter uses when the site names no ceiling of its own. */
    public const DEFAULT_TIMEOUT_SECONDS = 15;

    /**
     * The longest any caller can hold a request open, whatever it asks for.
     *
     * A ceiling and not a default: a provider that could name its own timeout
     * could name one that outlives the web server's own, which turns a slow
     * remote into an exhausted PHP-FPM pool.
     */
    public const MAX_TIMEOUT_SECONDS = 120;

    /**
     * The substrings that make a transport failure a timeout rather than an
     * unreachable host.
     *
     * Both hosts' clients raise the same kind of exception for a connection
     * failure and for a timeout, so the two are separated on the message where
     * it allows it: "the site cannot reach this host at all" and "the host is
     * slow" send a site owner to different places. Matching is
     * case-insensitive and the list is shared, so the two adapters cannot
     * classify one failure two ways.
     *
     * @var list<string>
     */
    public const TIMEOUT_MARKERS = ['timed out', 'timeout', 'timed-out'];

    private function __construct()
    {
    }

    /**
     * An exact host, or a subdomain of an entry written with a leading dot.
     *
     * Suffix matching without the dot is the classic mistake: `api.stripe.com`
     * would then also match `notapi.stripe.com`, which is a host an attacker
     * can register. The dot is what makes the entry mean "this host and
     * anything under it" rather than "anything ending in these letters".
     *
     * @param list<string> $allowedHosts lowercase hosts; a leading dot means the host and its subdomains
     */
    public static function allows(array $allowedHosts, string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return false;
        }

        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($allowed === $host) {
                return true;
            }

            if (str_starts_with($allowed, '.') && str_ends_with($host, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The caller's preference, lowered to the site's ceiling and never raised
     * by it.
     *
     * @param ?int $requested what the caller asked for, or null for the ceiling
     * @param int  $ceiling   the site's own ceiling, itself capped
     */
    public static function timeoutFor(?int $requested, int $ceiling = self::DEFAULT_TIMEOUT_SECONDS): int
    {
        $ceiling = max(1, min($ceiling, self::MAX_TIMEOUT_SECONDS));

        return $requested === null ? $ceiling : max(1, min($requested, $ceiling));
    }

    /**
     * Which refusal a transport failure is.
     *
     * Never an exception out of the port: both are answers, and an exception
     * out of a reconciliation pass stops the pass rather than recording one
     * failed call.
     */
    public static function classify(\Throwable|string $failure): string
    {
        $message = $failure instanceof \Throwable ? $failure->getMessage() : $failure;

        foreach (self::TIMEOUT_MARKERS as $marker) {
            if (stripos($message, $marker) !== false) {
                return HttpResponse::REFUSED_TIMEOUT;
            }
        }

        return HttpResponse::REFUSED_TRANSPORT;
    }
}
