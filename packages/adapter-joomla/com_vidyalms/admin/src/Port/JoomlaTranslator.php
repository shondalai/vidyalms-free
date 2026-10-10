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

use Joomla\CMS\Language\Language;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;

/**
 * The neutral catalogue served from Joomla language files.
 *
 * The key transform must stay identical to tooling/i18n/compile.php, which
 * generates the INI: "vidyalms.mail.enrolled.subject" becomes the constant
 * COM_VIDYALMS_MAIL_ENROLLED_SUBJECT. Placeholders stay {name} on both
 * platforms and are substituted here after translation.
 *
 * An untranslated constant falls back to a humanised form of the key, never
 * to the raw constant: an operator seeing "Course completed" learns more than
 * one seeing COM_VIDYALMS_MAIL_COURSE_COMPLETED_SUBJECT.
 *
 * @since 1.0.0
 */
final class JoomlaTranslator implements TranslatorPort
{
    public const EXTENSION = 'com_vidyalms';

    private bool $loaded = false;

    public function __construct(
        private readonly Language $language,
        private readonly string $basePath,
    ) {
    }

    public function translate(string $key, array $parameters = []): string
    {
        $this->loadCatalogue();

        $constant = self::constantFor($key);
        $translated = $this->language->_($constant);

        if ($translated === $constant) {
            $translated = self::humanise($key);
        }

        return $this->substitute($translated, $parameters);
    }

    /**
     * The transform used by tooling/i18n/compile.php. Changing one without the
     * other silently unhooks every string.
     */
    public static function constantFor(string $key): string
    {
        return strtoupper(str_replace(['vidyalms.', '.'], ['com_vidyalms.', '_'], $key));
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    private function substitute(string $text, array $parameters): string
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

    private function loadCatalogue(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $this->language->load(self::EXTENSION, $this->basePath);
    }

    private static function humanise(string $key): string
    {
        $words = trim(str_replace(['.', '_'], ' ', preg_replace('/^vidyalms\./', '', $key) ?? $key));

        return $words === '' ? $key : ucfirst(strtolower($words));
    }
}
