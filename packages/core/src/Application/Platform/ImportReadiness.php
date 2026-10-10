<?php
declare(strict_types=1);
namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterInterface;
use Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource\JoomlaSourceImporter;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\WordPressCourseImporter;

/** Database migrations without a real-install verification are opt-in tools. */
final class ImportReadiness
{
    public static function listed(ImporterInterface $importer): bool
    {
        return !($importer instanceof JoomlaSourceImporter || $importer instanceof WordPressCourseImporter)
            || (defined('VIDYALMS_ENABLE_EXPERIMENTAL_IMPORTERS') && constant('VIDYALMS_ENABLE_EXPERIMENTAL_IMPORTERS') === true);
    }
}
