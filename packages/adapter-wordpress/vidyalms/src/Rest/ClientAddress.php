<?php

declare(strict_types=1);

/**
 * VidyaLMS: the caller's address, established once for the whole plugin.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Rest;

defined('ABSPATH') || exit;

/**
 * One answer to "where did this request come from", for every entry point
 * that builds an ApiRequest.
 *
 * The core reads {@see \Shondalai\VidyaLMS\Core\Http\ApiRequest::$clientAddress}
 * from two places and never from a header: the guest rate-limit bucket, and
 * the salted ip_hash on an organisation access-log row. Both are answers to
 * the same question, and a site whose page routes and REST routes disagreed
 * about a caller's address would rate-limit one and audit the other, so the
 * rule lives here rather than once per controller.
 *
 * WordPress core reads REMOTE_ADDR and no forwarded header, and so does this:
 * a header the host did not validate is not an address. A site that
 * terminates TLS elsewhere hooks the filter rather than the core learning
 * about proxies.
 */
final class ClientAddress
{
    /**
     * Filter: (?string $address): ?string, for a site behind a proxy.
     *
     * The name is unchanged from the one Content\CoreRoute published, because
     * a site already hooking it must keep working and because one filter for
     * one fact is the whole point of this class.
     */
    public const FILTER = 'vidyalms_client_address';

    private function __construct()
    {
    }

    public static function resolve(): ?string
    {
        $remote = isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : null;
        $address = \is_string($remote) ? trim($remote) : '';

        /** @var mixed $filtered */
        $filtered = apply_filters('vidyalms_client_address', $address === '' ? null : $address);

        if (!\is_string($filtered) || trim($filtered) === '') {
            return null;
        }

        return filter_var(trim($filtered), \FILTER_VALIDATE_IP) === false ? null : trim($filtered);
    }
}
