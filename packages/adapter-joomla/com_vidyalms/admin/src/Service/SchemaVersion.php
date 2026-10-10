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

use Joomla\Database\DatabaseInterface;
use Shondalai\VidyaLMS\Core\Application\Platform\RecordSchemaVersion;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSchemaVersionRepository;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaDatabase;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\SystemClock;

/**
 * Which schema version this site is at, recorded where both hosts can be asked
 * the same question.
 *
 * The neutral schema declares a schema_version table and the core owns the use
 * case that writes it, and until now neither host called it: WordPress kept its
 * answer in an option and Joomla had no answer at all, so "which schema is this
 * site at" had two shapes and one blank. The row is written on install and on
 * every upgrade, and RecordSchemaVersion writes it once, so a repeated
 * installer run is a no-op rather than a second row.
 *
 * The version is not a constant here. The component ships one update script per
 * schema version and the packager refuses to build unless a script named for
 * tooling/schema/version.php exists, so the highest script the package carries
 * is the version its install SQL creates. Reading it keeps the number in one
 * place rather than adding a fourth copy of it that can drift.
 *
 * At tooling/schema/floor.php, the version of the first release, the folder
 * holds exactly one file and it carries no statements. That marker is not
 * ceremony: shipped() below answers an empty string for a folder with no
 * matching name, record() then writes nothing, and Joomla's own
 * Installer::setSchemaVersion() stamps #__schemas with the highest name in the
 * same folder. Both answers come from the name, so the name has to be there
 * even when the install script is what creates the tables.
 *
 * @since 1.1.0
 */
final class SchemaVersion
{
    /**
     * Where the update scripts live, relative to the component's admin folder.
     */
    public const UPDATES = 'sql/updates/mysql';

    private function __construct()
    {
    }

    /**
     * The highest schema version the package ships an update script for, or an
     * empty string when the directory holds none.
     */
    public static function shipped(string $directory): string
    {
        if (!is_dir($directory)) {
            return '';
        }

        $highest = '';

        foreach ((array) scandir($directory) as $entry) {
            if (!\is_string($entry) || preg_match('/^(\d+\.\d+\.\d+)\.sql$/', $entry, $parts) !== 1) {
                continue;
            }

            if ($highest === '' || version_compare($parts[1], $highest, '>')) {
                $highest = $parts[1];
            }
        }

        return $highest;
    }

    /**
     * Records the shipped version through the core use case.
     *
     * Returns the version recorded, or an empty string when there was nothing
     * to record. An installer must not die on this: a site whose schema_version
     * table is missing is a site mid-install, and the next run records it.
     */
    public static function record(DatabaseInterface $db, string $updatesDirectory): string
    {
        $version = self::shipped($updatesDirectory);

        if ($version === '') {
            return '';
        }

        $recorder = new RecordSchemaVersion(
            new DatabaseSchemaVersionRepository(new JoomlaDatabase($db), new SystemClock())
        );

        $recorder->execute($version);

        return $version;
    }
}
