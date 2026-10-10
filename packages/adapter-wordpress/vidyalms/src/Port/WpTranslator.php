<?php

declare(strict_types=1);

/**
 * VidyaLMS: the i18n port over gettext.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;

defined('ABSPATH') || exit;

/**
 * The neutral catalogue served through WordPress gettext.
 *
 * tooling/i18n/compile.php writes two files into src/I18n: catalogue.php, the
 * key => English map, and gettext.php, one literal _x() call per key with the
 * key as context and the English as the message. A key is translated by
 * looking its English up in the catalogue and asking for that string in the
 * key's context. The two files and this lookup have to agree; changing one
 * alone silently unhooks every string.
 *
 * The literal calls exist so translate.wordpress.org can extract every
 * message, including strings passed to the React apps. The plugin ships no
 * translation files: WordPress installs them as language packs.
 *
 * Placeholders stay {name} on both platforms and are substituted after
 * translation, so translators see one placeholder syntax and cannot break the
 * substitution by reordering.
 *
 * An unknown key returns a humanised form of the key, never an empty string: an
 * operator seeing "Course completed" learns more than one seeing nothing.
 */
final class WpTranslator implements TranslatorPort
{
    public const TEXT_DOMAIN = 'vidyalms';

    /** @var array<string, string>|null */
    private ?array $catalogue = null;

    /**
     * @param string $catalogueFile src/I18n/catalogue.php, compiled from packages/core/i18n/catalogue.php
     */
    public function __construct(
        private readonly string $catalogueFile,
    ) {
    }

    public function translate(string $key, array $parameters = []): string
    {
        $english = $this->catalogue()[$key] ?? null;

        $text = $english === null
            ? self::humanise($key)
            : self::text($key, $english);

        return self::substitute($text, $parameters);
    }

    public static function text(string $key, string $english): string
    {
        static $translate;
        $translate ??= require \dirname(__DIR__) . '/I18n/gettext.php';

        return $translate($key, $english);
    }

    /**
     * @return array<string, string>
     */
    private function catalogue(): array
    {
        if ($this->catalogue !== null) {
            return $this->catalogue;
        }

        $loaded = is_readable($this->catalogueFile) ? require $this->catalogueFile : null;

        if (!\is_array($loaded)) {
            return $this->catalogue = [];
        }

        $catalogue = [];

        foreach ($loaded as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $catalogue[$key] = $value;
            }
        }

        return $this->catalogue = $catalogue;
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private static function substitute(string $text, array $parameters): string
    {
        if ($parameters === []) {
            return $text;
        }

        $replacements = [];

        foreach ($parameters as $name => $value) {
            $replacements['{' . $name . '}'] = $value === null ? '' : (string) $value;
        }

        return strtr($text, $replacements);
    }

    private static function humanise(string $key): string
    {
        $stripped = preg_replace('/^vidyalms\./', '', $key) ?? $key;
        $words = trim(str_replace(['.', '_'], ' ', $stripped));

        return $words === '' ? $key : ucfirst(strtolower($words));
    }
}
