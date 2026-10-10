<?php

declare(strict_types=1);

/**
 * VidyaLMS: the locale port over WordPress.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;

defined('ABSPATH') || exit;

/**
 * The locale of the current request.
 *
 * A port of its own rather than a method on the translator, for the reason
 * LocalePort states: a paid package implements TranslatorPort too, and adding
 * a method to it fatals an installed paid build at class load the moment the
 * free build is upgraded.
 *
 * WordPress spells a locale "de_DE"; every context object in the core carries
 * the BCP 47 form, so the underscore is normalised here rather than in each
 * reader. get_user_locale() rather than get_locale(), because the two mount
 * configs already send the user's own locale to the React apps and the server
 * must not disagree with them about which language the request is in.
 */
final class WpLocale implements LocalePort
{
    public const DEFAULT = 'en-GB';

    public function current(): string
    {
        $locale = \function_exists('get_user_locale') ? get_user_locale() : '';

        if (!\is_string($locale) || $locale === '') {
            return self::DEFAULT;
        }

        return str_replace('_', '-', $locale);
    }
}
