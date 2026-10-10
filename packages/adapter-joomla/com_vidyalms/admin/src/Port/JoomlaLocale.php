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
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;

/**
 * The locale Joomla resolved for this request.
 *
 * A port of its own rather than a method on the translator, for the reason
 * LocalePort states: a paid package implements TranslatorPort too, and adding
 * a method to it fatals an installed paid build at class load the moment the
 * free build is upgraded.
 *
 * Joomla's language tag is already BCP 47 ("en-GB", "de-DE"), so nothing is
 * normalised here.
 *
 * @since 1.0.0
 */
final class JoomlaLocale implements LocalePort
{
    public function __construct(private readonly Language $language)
    {
    }

    public function current(): string
    {
        $tag = $this->language->getTag();

        return $tag === '' ? 'en-GB' : $tag;
    }
}
