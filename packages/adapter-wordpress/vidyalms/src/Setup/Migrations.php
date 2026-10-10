<?php

declare(strict_types=1);

/**
 * VidyaLMS: the data half of a schema upgrade on WordPress.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Setup;

use Shondalai\VidyaLMS\WordPress\Plugin;
use Shondalai\VidyaLMS\WordPress\Port\WpLogger;

defined('ABSPATH') || exit;

/**
 * What dbDelta cannot do.
 *
 * dbDelta creates a missing table and adds a missing column, and that is the
 * whole of its vocabulary. It cannot write a row, so every migration that has
 * to say something about existing data (that an existing multiple-choice
 * question keeps the marking rule it already graded under, for instance) needs
 * a place to live. This is that place, and it is the WordPress counterpart of
 * the Joomla component's sql/updates/mysql directory.
 *
 * One file per schema version, named for the version it upgrades to, holding a
 * list of SQL statements with a {prefix} placeholder. They run in version
 * order after dbDelta, and only those above the version the site currently
 * records: a file that has already run never runs again on a healthy site.
 *
 * Every statement must still be written to be repeatable, because a site can
 * be unhealthy. The stored schema version is advanced only when the whole set
 * ran cleanly, so a run that fails half way is repeated from the top of that
 * version rather than skipped, which is the failure that would otherwise leave
 * a column added and its pins never written.
 *
 * A version whose file returns an empty list is a clean run and not a failure,
 * and a file that cannot be read is a failure and is told apart from it:
 * statements() answers null for the first and a list for the second. The two
 * used to be one answer here, which meant an unreadable file and a file with
 * nothing to say were both FAILED, activate() never wrote the option, and
 * Plugin::upgradeSchemaIfNeeded repeated the whole upgrade on every admin
 * request with nothing in the log to explain it. Pro's Setup\Migrations has
 * always drawn the line here and this is the same line.
 *
 * The directory is empty at the install floor, and that is the correct state
 * rather than a missing file. tooling/schema/floor.php is the version of the
 * first release; nothing older ever shipped, so no site can hold anything
 * older, and the compiled schema installs the floor outright. A file named for
 * the floor would run on nothing, because pending() starts strictly above the
 * version the site records and a fresh activation records the floor. The
 * packager refuses a file below the floor for that reason and one above
 * Activator::SCHEMA_VERSION because pending() stops before it.
 *
 * versions() and pending() are unchanged by that: an empty directory answers
 * the empty list, run() runs nothing and reports nothing failed, and the
 * activation records the version as it always did. The first version above the
 * floor with anything to say about existing rows drops its file in and the
 * machinery is already there, which build/schema-lifecycle-test.php --synthetic
 * proves without shipping a file.
 */
final class Migrations
{
    /**
     * Where the version files live inside the plugin. Shipped, not generated:
     * a migration is a decision about data, and the schema compiler knows
     * nothing about data.
     */
    public const DIRECTORY = 'schema/migrations';

    private function __construct()
    {
    }

    /**
     * Every version that has a migration file, oldest first.
     *
     * @return list<string>
     */
    public static function versions(): array
    {
        $directory = Plugin::path(self::DIRECTORY);

        if (!is_dir($directory)) {
            return [];
        }

        $found = [];

        foreach ((array) scandir($directory) as $entry) {
            if (!\is_string($entry) || !preg_match('/^(\d+\.\d+\.\d+)\.php$/', $entry, $parts)) {
                continue;
            }

            if (is_readable($directory . '/' . $entry)) {
                $found[] = $parts[1];
            }
        }

        usort($found, 'version_compare');

        return $found;
    }

    /**
     * The versions a site sitting at $from still has to run to reach $to.
     *
     * A site with no recorded version is a fresh install, whose tables were
     * created at the current shape. Its migrations still run: they are
     * idempotent and match nothing, which costs one statement each and means
     * a site that lost its option row is repaired rather than left half
     * migrated.
     *
     * @return list<string>
     */
    public static function pending(?string $from, string $to): array
    {
        $pending = [];

        foreach (self::versions() as $version) {
            if ($from !== null && version_compare($version, $from, '<=')) {
                continue;
            }

            if (version_compare($version, $to, '>')) {
                continue;
            }

            $pending[] = $version;
        }

        return $pending;
    }

    /**
     * Runs every pending migration.
     *
     * Returns the versions that ran cleanly and the ones that did not. A
     * caller that sees a failure must leave the recorded schema version where
     * it was, so the next pass runs the same file again.
     *
     * @return array{ran: list<string>, failed: list<string>, statements: int}
     */
    public static function run(?string $from, string $to): array
    {
        global $wpdb;

        if (preg_match('/^[A-Za-z0-9_]+$/D', $wpdb->prefix) !== 1) {
            throw new \RuntimeException('The WordPress table prefix is not a safe SQL identifier.');
        }

        $ran = [];
        $failed = [];
        $executed = 0;

        foreach (self::pending($from, $to) as $version) {
            $statements = self::statements($version);

            if ($statements === null) {
                $failed[] = $version;

                continue;
            }

            $clean = true;

            foreach ($statements as $statement) {
                $sql = str_replace('{prefix}', $wpdb->prefix, $statement);

                // SQL comes only from shipped migration files; the substituted prefix is validated above.
                // Runs shipped SQL with a validated prefix under the schema lock; it is not user-supplied or cacheable content.
                // phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                if ($wpdb->query($sql) === false) {
                    $clean = false;

                    (new WpLogger())->error('VidyaLMS migration {version} failed: {error}', [
                        'version' => $version,
                        'error' => $wpdb->last_error,
                        'statement' => $sql,
                    ]);

                    continue;
                }

                $executed++;
            }

            if ($clean) {
                $ran[] = $version;
            } else {
                $failed[] = $version;
            }
        }

        return ['ran' => $ran, 'failed' => $failed, 'statements' => $executed];
    }

    /**
     * The statements of one version file, with {prefix} still in them, or null
     * when the file cannot be read or does not return a list.
     *
     * An empty list is a real answer and not a symptom: see the class docblock.
     *
     * @return list<string>|null
     */
    public static function statements(string $version): ?array
    {
        $file = Plugin::path(self::DIRECTORY . '/' . $version . '.php');

        if (!is_readable($file)) {
            return null;
        }

        /** @var mixed $loaded */
        $loaded = require $file;

        if (!\is_array($loaded)) {
            return null;
        }

        $statements = [];

        foreach ($loaded as $statement) {
            if (\is_string($statement) && trim($statement) !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }
}
