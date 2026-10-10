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

use Joomla\Utilities\IpHelper;

/**
 * One answer to "where did this request come from", for every entry point
 * that builds an ApiRequest.
 *
 * The core reads {@see \Shondalai\VidyaLMS\Core\Http\ApiRequest::$clientAddress}
 * from two places and never from a header: the guest rate-limit bucket, and
 * the salted ip_hash on an organisation access-log row. Both are answers to
 * the same question, and a site whose page routes and REST routes disagreed
 * about a caller's address would rate-limit one and audit the other, so the
 * rule lives here rather than once per caller.
 *
 * IpHelper is the host's own answer and honours the site's configured
 * behind-a-proxy setting, so a site behind a load balancer places its callers
 * correctly and one that is not does not trust a forwarded header. An address
 * the host cannot give is null, and the core's guest bucket then falls back to
 * its coarse form while the access log records no ip_hash at all, which is the
 * honest reading of "the host could not establish one".
 *
 * @since 1.0.0
 */
final class ClientAddress
{
    private function __construct()
    {
    }

    public static function resolve(): ?string
    {
        try {
            $address = trim((string) IpHelper::getIp());
        } catch (\Throwable) {
            return null;
        }

        return $address === '' ? null : $address;
    }
}
