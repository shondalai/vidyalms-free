<?php

declare(strict_types=1);

/**
 * VidyaLMS: a recipient's own timezone and locale, from the WordPress user.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Shondalai\VidyaLMS\Core\Port\Users\RecipientProfilePort;

defined('ABSPATH') || exit;

/**
 * The port had no implementer anywhere, so both shipped hosts answered the
 * site defaults for every recipient. Two consequences follow, and neither is
 * speculative: notification_templates is keyed by locale and resolved at
 * send, so a multilingual site could only ever select the site row; and a
 * channel whose quiet-hours window is statutory refuses to send when no
 * per-recipient timezone is available, so the paid SMS channel refused for
 * every recipient on every install.
 *
 * WordPress core stores a per-user locale and no per-user timezone, so the
 * locale is read from the user and the timezone is asked of a filter, which
 * is what the plugins that add one already hook. Both may legitimately answer
 * nothing: a user who chose neither inherits the site's, which is what null
 * means here and what the fixed default already answered.
 */
final class WpRecipientProfile implements RecipientProfilePort
{
    /**
     * Filter: (?string $timezone, int $userId): ?string, an IANA zone.
     *
     * WordPress has no per-user timezone of its own, so this is the seam the
     * plugins that add one hook rather than this class guessing.
     */
    public const TIMEZONE_FILTER = 'vidyalms_recipient_timezone';

    public function timezoneFor(int $userId): ?string
    {
        if ($userId < 1) {
            return null;
        }

        $stored = get_user_meta($userId, 'vidyalms_timezone', true);
        $timezone = \is_string($stored) ? trim($stored) : '';

        /** @var mixed $filtered */
        $filtered = apply_filters('vidyalms_recipient_timezone', $timezone === '' ? null : $timezone, $userId);

        return \is_string($filtered) && trim($filtered) !== '' ? trim($filtered) : null;
    }

    public function localeFor(int $userId): ?string
    {
        if ($userId < 1) {
            return null;
        }

        $locale = get_user_locale($userId);

        return \is_string($locale) && trim($locale) !== '' ? str_replace('_', '-', trim($locale)) : null;
    }
}
