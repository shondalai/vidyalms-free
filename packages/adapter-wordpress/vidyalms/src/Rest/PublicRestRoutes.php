<?php

declare(strict_types=1);

/**
 * Public endpoints declared by the shared API contract.
 *
 * @package VidyaLMS
 * @license GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Rest;

defined('ABSPATH') || exit;

final class PublicRestRoutes
{
    public static function allows(string $method, string $path): bool
    {
        $path = '/' . trim($path, '/');
        $method = strtoupper($method);
        if ($method === 'GET' && preg_match(
            '~^/(?:courses(?:/[^/]+(?:/curriculum)?)?|lessons/[^/]+|(?:certificates|badges)/[^/]+/verify|credentials/(?:status/)?[^/]+)$~D',
            $path,
        ) === 1) {
            return true;
        }
        // Token/signature verification and rate limits remain mandatory in the core handlers.
        return (\in_array($method, ['GET', 'POST'], true)
                && preg_match('~^/notifications/unsubscribe/[^/]+$~D', $path) === 1)
            || ($method === 'POST' && preg_match('~^/notifications/feedback/[^/]+$~D', $path) === 1);
    }
}
