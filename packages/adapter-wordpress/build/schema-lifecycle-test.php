<?php

/**
 * VidyaLMS: the free WordPress schema lifecycle, rehearsed offline.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 *
 * The WordPress half of packages/adapter-joomla/build/schema-lifecycle-test.php,
 * asking the same three questions of the same shipped artefacts in a throwaway
 * MySQL database this script creates and drops:
 *
 *   1. does the compiled schema build the shape the neutral schema declares
 *   2. does the install path record the schema version exactly once
 *   3. does an update still apply and still get recorded, on a tree whose
 *      migrations directory is empty because the first release installs at the
 *      floor and nothing older exists to upgrade from
 *
 * WordPress is not loaded. Setup\Activator::statements() and Setup\Migrations
 * are the shipped classes, required directly, with stand-ins for the four host
 * symbols they touch (Plugin::path, $wpdb, WpScheduler::TABLE and __()). What
 * that does not prove is dbDelta's own parser, which only WordPress has; the
 * statements executed here are the exact strings the activator hands it, and a
 * live install on the development site is what turns this into a fact.
 *
 * Usage:
 *   php packages/adapter-wordpress/build/schema-lifecycle-test.php \
 *       [--host=127.0.0.1] [--user=root] [--password=...] [--prefix=wp_] \
 *       [--baseline=<old-chain-wordpress-free.json>] [--synthetic] [--keep]
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress {
    /**
     * Stand-in for the plugin bootstrap. Only path() is reached from the two
     * classes under test, and it answers the real shipped plugin directory, so
     * the schema and the migrations they read are the shipped ones.
     */
    final class Plugin
    {
        public static string $root = '';

        public static function path(string $relative = ''): string
        {
            return rtrim(self::$root, '/') . '/' . ltrim($relative, '/');
        }
    }
}

namespace {
    use Shondalai\VidyaLMS\Core\Application\Platform\RecordSchemaVersion;
    use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSchemaVersionRepository;
    use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
    use Shondalai\VidyaLMS\Tooling\Schema\RehearsalDatabase;
    use Shondalai\VidyaLMS\WordPress\Plugin;
    use Shondalai\VidyaLMS\WordPress\Setup\Activator;
    use Shondalai\VidyaLMS\WordPress\Setup\Migrations;
    use Shondalai\VidyaLMS\WordPress\Setup\SchemaShape;

    if (PHP_SAPI !== 'cli') {
        fwrite(STDERR, "schema-lifecycle-test.php runs on the command line only.\n");
        exit(1);
    }

    $adapterDir = \dirname(__DIR__);
    $repoRoot   = \dirname($adapterDir, 2);
    $pluginDir  = $adapterDir . '/vidyalms';

    require_once $repoRoot . '/packages/core/vendor/autoload.php';
    require_once $repoRoot . '/tooling/schema/rehearsal-database.php';

    Plugin::$root = $pluginDir;

    \define('ABSPATH', $pluginDir . '/');
    \define('ARRAY_A', 'ARRAY_A');

    /**
     * The one table the synthetic upgrade adds its column to.
     */
    \define('SYNTHETIC_TABLE', 'idempotency_keys');
    \define('SYNTHETIC_COLUMN', 'r13_rehearsal_marker');

    /**
     * WordPress's translation function, which Activator calls in one error
     * path. Nothing here asserts on a translation.
     */
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }

    /**
     * The site connection, as much of it as the two shipped classes touch:
     * the prefix they build table names from, the query they run a migration
     * statement through, and the error they read when it fails.
     */
    class wpdb
    {
        public string $last_error = '';

        /** @var list<string> */
        public array $executed = [];

        public function __construct(
            public string $prefix,
            private readonly PDO $pdo,
        ) {
        }

        public function query(string $sql): int|bool
        {
            $this->last_error = '';

            try {
                $affected = $this->pdo->exec($sql);
                $this->executed[] = $sql;

                return $affected === false ? false : $affected;
            } catch (PDOException $failure) {
                $this->last_error = $failure->getMessage();

                return false;
            }
        }

        public function prepare(string $sql, mixed ...$args): string
        {
            return preg_replace_callback('/%[isd]/', function (array $match) use (&$args): string {
                $value = array_shift($args);
                return match ($match[0]) {
                    '%i' => '`' . str_replace('`', '``', (string) $value) . '`',
                    '%d' => (string) (int) $value,
                    default => $this->pdo->quote((string) $value),
                };
            }, $sql) ?? throw new RuntimeException('Invalid rehearsal query.');
        }

        /**
         * The metadata read Setup\SchemaShape makes after dbDelta. WordPress
         * answers null on a failed query and an array of rows otherwise, and
         * the shape check tells those apart, so the stand-in has to as well.
         *
         * @return list<array<string, mixed>>|null
         */
        public function get_results(string $sql, string $output = 'OBJECT'): ?array
        {
            $this->last_error = '';

            try {
                /** @var list<array<string, mixed>> $rows */
                $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

                return $rows;
            } catch (PDOException $failure) {
                $this->last_error = $failure->getMessage();

                return null;
            }
        }
    }

    require_once $pluginDir . '/src/Port/WpScheduler.php';
    require_once $pluginDir . '/src/Setup/SchemaShape.php';
    require_once $pluginDir . '/src/Setup/Activator.php';
    require_once $pluginDir . '/src/Setup/Migrations.php';

    $options = [
        'host' => getenv('VIDYALMS_DB_HOST') ?: '127.0.0.1',
        'user' => getenv('VIDYALMS_DB_USER') ?: 'root',
        'password' => getenv('VIDYALMS_DB_PASSWORD') ?: '',
        'prefix' => 'wp_',
        'baseline' => '',
        'synthetic' => false,
        'keep' => false,
    ];

    foreach (\array_slice($argv, 1) as $argument) {
        if (preg_match('/^--(host|user|password|prefix|baseline)=(.*)$/', $argument, $found) === 1) {
            $options[$found[1]] = $found[2];
        } elseif ($argument === '--synthetic') {
            $options['synthetic'] = true;
        } elseif ($argument === '--keep') {
            $options['keep'] = true;
        } elseif ($argument === '--help' || $argument === '-h') {
            echo "Usage: php build/schema-lifecycle-test.php [--host=] [--user=] [--password=]\n"
                . "       [--prefix=wp_] [--baseline=<json>] [--synthetic] [--keep]\n";
            exit(0);
        } else {
            fwrite(STDERR, 'Unknown argument: ' . $argument . "\n");
            exit(1);
        }
    }

    $failures = [];
    $notes    = [];

    function out(string $line): void
    {
        echo $line . "\n";
    }

    function step(string $line): void
    {
        echo "\n" . $line . "\n" . str_repeat('-', \strlen($line)) . "\n";
    }

    /**
     * @param list<string> $failures
     */
    function check(array &$failures, bool $condition, string $claim): void
    {
        out(($condition ? '  ok   ' : '  FAIL ') . $claim);

        if (!$condition) {
            $failures[] = $claim;
        }
    }

    /**
     * The information_schema shape of one database, in the same encoding the
     * R13 baseline recorded, so the two can be compared line for line.
     *
     * @param  list<string> $tables
     * @return array{tables: list<string>, columns: list<string>, indexes: list<string>}
     */
    function shapeOf(PDO $pdo, string $database, array $tables): array
    {
        if ($tables === []) {
            return ['tables' => [], 'columns' => [], 'indexes' => []];
        }

        $in = implode(',', array_map(static fn (string $t): string => "'" . $t . "'", $tables));

        $columns = $pdo->query(sprintf(
            "SELECT CONCAT(TABLE_NAME,'.',COLUMN_NAME,':',COLUMN_TYPE,':',IS_NULLABLE,':',IFNULL(COLUMN_DEFAULT,'~'),"
            . "':',IFNULL(CHARACTER_SET_NAME,'~'),':',IFNULL(COLLATION_NAME,'~'),':',EXTRA)"
            . " FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='%s' AND TABLE_NAME IN (%s)"
            . ' ORDER BY TABLE_NAME, COLUMN_NAME',
            $database,
            $in,
        ))->fetchAll(PDO::FETCH_COLUMN);

        $indexes = $pdo->query(sprintf(
            "SELECT CONCAT(TABLE_NAME,'.',INDEX_NAME,'.',SEQ_IN_INDEX,':',COLUMN_NAME,':',NON_UNIQUE,':',IFNULL(SUB_PART,'~'))"
            . " FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='%s' AND TABLE_NAME IN (%s)"
            . ' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            $database,
            $in,
        ))->fetchAll(PDO::FETCH_COLUMN);

        $meta = $pdo->query(sprintf(
            "SELECT CONCAT(TABLE_NAME,':',ENGINE,':',TABLE_COLLATION) FROM information_schema.TABLES"
            . " WHERE TABLE_SCHEMA='%s' AND TABLE_NAME IN (%s) ORDER BY TABLE_NAME",
            $database,
            $in,
        ))->fetchAll(PDO::FETCH_COLUMN);

        /** @var list<string> $meta */
        /** @var list<string> $columns */
        /** @var list<string> $indexes */
        return ['tables' => $meta, 'columns' => $columns, 'indexes' => $indexes];
    }

    /**
     * @return list<string>
     */
    function productTables(PDO $pdo, string $database, string $prefix): array
    {
        /** @var list<string> */
        return $pdo->query(sprintf(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='%s'"
            . " AND TABLE_NAME LIKE '%svidyalms\\_%%' ORDER BY TABLE_NAME",
            $database,
            $prefix,
        ))->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param  list<string> $mine
     * @param  list<string> $theirs
     * @return list<string>
     */
    function differences(array $mine, array $theirs, string $label): array
    {
        $only = array_map(
            static fn (string $entry): string => $label . ' only in the install: ' . $entry,
            array_values(array_diff($mine, $theirs)),
        );

        $missing = array_map(
            static fn (string $entry): string => $label . ' only in the old chain: ' . $entry,
            array_values(array_diff($theirs, $mine)),
        );

        return [...$only, ...$missing];
    }

    /**
     * The next patch version above $version, which is where a migration has to
     * live for a site already at $version to be able to run it.
     */
    function synthetic(string $version): string
    {
        $parts = array_map('intval', explode('.', $version));

        return sprintf('%d.%d.%d', $parts[0] ?? 1, $parts[1] ?? 0, ($parts[2] ?? 0) + 1);
    }

    function syntheticMigration(string $version): string
    {
        return "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "/**\n"
            . ' * VidyaLMS ' . $version . " (SYNTHETIC, written and removed by build/schema-lifecycle-test.php).\n"
            . " *\n"
            . " * Nothing ships this file. It exists for the length of one rehearsal so the\n"
            . " * update path is exercised on a tree whose migrations directory is empty:\n"
            . " * the mechanism is proved rather than assumed until the first real schema\n"
            . " * change needs it.\n"
            . " */\n\n"
            . "return [\n"
            . "    'ALTER TABLE `{prefix}vidyalms_" . SYNTHETIC_TABLE . '` ADD COLUMN `'
            . SYNTHETIC_COLUMN . "` VARCHAR(20) NULL',\n"
            . "];\n";
    }

    $clock = new class implements ClockPort {
        public function now(): \DateTimeImmutable
        {
            return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }
    };

    $migrationDir = $pluginDir . '/' . Migrations::DIRECTORY;
    $version      = (string) require $repoRoot . '/tooling/schema/version.php';
    $floor        = (string) require $repoRoot . '/tooling/schema/floor.php';
    $database     = 'vidyalms_r13_free_wordpress';
    $prefix       = $options['prefix'];

    out('VidyaLMS free WordPress schema lifecycle rehearsal');
    out('  schema version : ' . $version);
    out('  install floor  : ' . $floor);
    out('  activator says : ' . Activator::SCHEMA_VERSION);
    out('  database       : ' . $database . ' (created and dropped by this script)');
    out('  prefix         : ' . $prefix);

    // -----------------------------------------------------------------------
    // Step 1: the shipped tree says what it is
    // -----------------------------------------------------------------------

    step('Step 1: the migrations directory matches the floor invariant');

    $shipped = Migrations::versions();

    check($failures, version_compare($version, $floor, '>='), 'the schema version is at or above the install floor');
    check($failures, Activator::SCHEMA_VERSION === $version, 'Activator::SCHEMA_VERSION is the repository schema version');
    check(
        $failures,
        array_filter($shipped, static fn (string $v): bool => version_compare($v, $version, '>')) === [],
        'no migration file is above the schema version',
    );
    check(
        $failures,
        array_filter($shipped, static fn (string $v): bool => version_compare($v, $floor, '<')) === [],
        'no migration file is below the install floor',
    );
    check($failures, Migrations::pending(null, $version) === $shipped, 'a fresh site owes every shipped migration and no more');
    check($failures, Migrations::pending($version, $version) === [], 'a site already at the version owes nothing');

    out('  files          : ' . ($shipped === [] ? '(none, the install creates the floor)' : implode(', ', $shipped)));

    // -----------------------------------------------------------------------
    // Step 2: install into a throwaway database
    // -----------------------------------------------------------------------

    step('Step 2: install the compiled schema into ' . $database);

    $pdo = new PDO(
        sprintf('mysql:host=%s;charset=utf8mb4', $options['host']),
        $options['user'],
        $options['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    $pdo->exec('CREATE DATABASE `' . $database . '` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo->exec('USE `' . $database . '`');

    $wpdb = new wpdb($prefix, $pdo);

    try {
        // The shipped activator builds them, placeholder substitution, dbDelta
        // normalisation, adapter-owned job table and all. On an empty database
        // dbDelta's whole vocabulary is CREATE TABLE, so running them is what
        // it would do.
        $statements = Activator::statements();
        $executed   = 0;

        foreach ($statements as $statement) {
            $pdo->exec($statement);
            $executed++;
        }

        $tables = productTables($pdo, $database, $prefix);

        out('  statements     : ' . $executed);
        out('  tables         : ' . \count($tables));

        check($failures, $executed === \count($statements), 'every compiled statement ran');
        check($failures, \count($tables) === \count($statements), 'one table per statement');

        // -------------------------------------------------------------------
        // Step 3: the version an activation records
        // -------------------------------------------------------------------

        step('Step 3: the installed version is recorded where both hosts can be asked');

        $migrated = Migrations::run(null, Activator::SCHEMA_VERSION);

        check($failures, $migrated['failed'] === [], 'nothing failed, so the activation records the version');
        check($failures, $migrated['ran'] === $shipped, 'the migrations that ran are the ones the directory holds');

        $port = new RehearsalDatabase($pdo, $prefix);
        $recorder = new RecordSchemaVersion(new DatabaseSchemaVersionRepository($port, $clock));
        $recorder->execute(Activator::SCHEMA_VERSION);
        $recorder->execute(Activator::SCHEMA_VERSION);

        $recorded = $pdo->query(
            'SELECT `version` FROM `' . $prefix . 'vidyalms_schema_version` ORDER BY `id`',
        )->fetchAll(PDO::FETCH_COLUMN);

        out('  recorded       : ' . implode(', ', $recorded));
        check($failures, $recorded === [$version], 'one row, holding ' . $version . ', after two activations');

        // -------------------------------------------------------------------
        // Step 4: the shape, against the recorded old-chain dump
        // -------------------------------------------------------------------

        $shape = shapeOf($pdo, $database, $tables);

        out('');
        out('  columns        : ' . \count($shape['columns']));
        out('  index parts    : ' . \count($shape['indexes']));

        if ($options['baseline'] !== '') {
            step('Step 5: the collapsed install against the recorded old chain');

            /** @var array{information_schema: array{tables: list<string>, columns: list<string>, indexes: list<string>}} $baseline */
            $baseline = json_decode(
                (string) file_get_contents((string) $options['baseline']),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            $found = [
                ...differences($shape['tables'], $baseline['information_schema']['tables'], 'table'),
                ...differences($shape['columns'], $baseline['information_schema']['columns'], 'column'),
                ...differences($shape['indexes'], $baseline['information_schema']['indexes'], 'index part'),
            ];

            foreach (\array_slice($found, 0, 25) as $difference) {
                out('  ' . $difference);
            }

            out('  differences    : ' . \count($found));
            check($failures, $found === [], 'the collapsed install reproduces the old chain exactly');

        } else {
            $notes[] = 'no --baseline was given, so the shape was measured but not compared';
        }

        // -------------------------------------------------------------------
        // Step 5: the update path, proved and then taken away again
        // -------------------------------------------------------------------

        if ($options['synthetic']) {
            step('Step 5: a synthetic update above the floor applies and is recorded');

            $syntheticVersion = synthetic($version);
            $syntheticFile    = $migrationDir . '/' . $syntheticVersion . '.php';

            check($failures, !is_file($syntheticFile), 'the tree holds no ' . $syntheticVersion . '.php before the rehearsal');
            check(
                $failures,
                \in_array($prefix . 'vidyalms_' . SYNTHETIC_TABLE, $tables, true),
                'the synthetic upgrade has a table to alter (' . SYNTHETIC_TABLE . ')',
            );

            if (file_put_contents($syntheticFile, syntheticMigration($syntheticVersion)) === false) {
                throw new RuntimeException('Cannot write the synthetic migration ' . $syntheticFile);
            }

            try {
                check(
                    $failures,
                    Migrations::pending($version, $syntheticVersion) === [$syntheticVersion],
                    'a site at ' . $version . ' now owes ' . $syntheticVersion,
                );

                $upgrade = Migrations::run($version, $syntheticVersion);

                $column = $pdo->query(sprintf(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='%s'"
                    . " AND TABLE_NAME='%s' AND COLUMN_NAME='%s'",
                    $database,
                    $prefix . 'vidyalms_' . SYNTHETIC_TABLE,
                    SYNTHETIC_COLUMN,
                ))->fetchColumn();

                check($failures, $upgrade['ran'] === [$syntheticVersion], 'the migration ran cleanly');
                check($failures, $upgrade['statements'] === 1, 'one statement was executed');
                check($failures, (int) $column === 1, 'the synthetic column arrived on ' . SYNTHETIC_TABLE);

                $recorder->execute($syntheticVersion);

                $after = $pdo->query(
                    'SELECT `version` FROM `' . $prefix . 'vidyalms_schema_version` ORDER BY `id`',
                )->fetchAll(PDO::FETCH_COLUMN);

                out('  recorded       : ' . implode(', ', $after));
                check($failures, $after === [$version, $syntheticVersion], 'the upgrade was recorded beside the install');
            } finally {
                unlink($syntheticFile);
            }

            // A version with nothing to say about rows is a clean run, and an
            // unreadable file is a failure. They used to be the same answer,
            // which left a DDL-only version recorded as FAILED for ever: the
            // option was never written and the upgrade repeated on every
            // admin request. Pro drew this line first; the free runner now
            // draws the same one, and this is where it is proved.
            check(
                $failures,
                Migrations::statements($syntheticVersion) === null,
                'a migration file that is not there answers the failure sentinel',
            );

            if (file_put_contents($syntheticFile, "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n") === false) {
                throw new RuntimeException('Cannot write the empty synthetic migration ' . $syntheticFile);
            }

            try {
                check(
                    $failures,
                    Migrations::statements($syntheticVersion) === [],
                    'a migration file with nothing to say answers an empty list, not the sentinel',
                );

                $empty = Migrations::run($version, $syntheticVersion);

                check(
                    $failures,
                    $empty === ['ran' => [$syntheticVersion], 'failed' => [], 'statements' => 0],
                    'and running it is a clean run, so the version is recorded rather than repeated for ever',
                );
            } finally {
                unlink($syntheticFile);
            }

            check($failures, !is_file($syntheticFile), 'the synthetic migration file was removed');
            check($failures, Migrations::versions() === $shipped, 'the directory holds what it held before');
        } else {
            $notes[] = 'the update path was not exercised; pass --synthetic to prove it';
        }

        // -------------------------------------------------------------------
        // Step 7: the post-condition dbDelta itself cannot give
        // -------------------------------------------------------------------

        step('Step 7: the installed shape is asserted, and the assertion bites');

        // dbDelta reports planned changes, not accepted ones. The deleted
        // WordPress chain carried a hundred and nine SELECT shape probes for
        // this, every one of which ran on a fresh install; Setup\SchemaShape
        // is what replaced them. A check that can only ever pass is not a
        // check, so the damage is done on purpose and then undone.
        SchemaShape::assertInstalled($statements);
        check($failures, true, 'the installed schema satisfies the compiled shape');

        $damaged = '';
        $droppedColumn = '';
        $droppedIndex = '';

        foreach (SchemaShape::expected($statements) as $table => $expected) {
            $indexed = [];

            foreach ($expected['indexes'] as $index) {
                foreach ($index['columns'] as $columnName) {
                    $indexed[$columnName] = true;
                }
            }

            $spare = array_values(array_filter(
                $expected['columns'],
                static fn (string $columnName): bool => !isset($indexed[$columnName]),
            ));
            $secondary = array_values(array_filter(
                array_keys($expected['indexes']),
                static fn (string $name): bool => $name !== 'primary',
            ));

            if ($spare === [] || $secondary === []) {
                continue;
            }

            $damaged = $table;
            $droppedColumn = $spare[0];
            $droppedIndex = $secondary[0];

            break;
        }

        check($failures, $damaged !== '', 'a table with a spare column and a secondary index was found to damage');

        if ($damaged !== '') {
            out('  damaging       : ' . $damaged . ' (drop ' . $droppedColumn . ', drop index ' . $droppedIndex . ')');

            $pdo->exec('ALTER TABLE `' . $damaged . '` DROP COLUMN `' . $droppedColumn . '`');
            $pdo->exec('ALTER TABLE `' . $damaged . '` DROP INDEX `' . $droppedIndex . '`');

            $refused = '';

            try {
                SchemaShape::assertInstalled($statements);
            } catch (RuntimeException $thrownShape) {
                $refused = $thrownShape->getMessage();
            }

            check($failures, $refused !== '', 'a silently incomplete dbDelta is refused rather than recorded');
            check(
                $failures,
                str_contains($refused, 'missing column(s) ' . $droppedColumn),
                'the refusal names the column that never arrived',
            );
            check(
                $failures,
                str_contains($refused, 'missing index ' . $droppedIndex),
                'and the index, which is the failure dbDelta hides best',
            );

            // Put it back exactly, by dropping the damaged table and running
            // its own compiled statement again: an ALTER guessed from the
            // shape would restore the name and not the type, and --keep hands
            // this database to a human.
            foreach ($statements as $statement) {
                if (preg_match('/^CREATE TABLE\s+`?' . preg_quote($damaged, '/') . '`?\s/i', trim($statement)) !== 1) {
                    continue;
                }

                $pdo->exec('DROP TABLE `' . $damaged . '`');
                $pdo->exec($statement);

                break;
            }

            SchemaShape::assertInstalled($statements);
            check($failures, true, 'and the damaged table was restored to the compiled shape');
        }
    } catch (\Throwable $thrown) {
        $failures[] = 'the rehearsal threw ' . $thrown::class . ': ' . $thrown->getMessage();
    } finally {
        if ($options['keep']) {
            out("\nkept " . $database);
        } else {
            $pdo->exec('USE `information_schema`');
            $pdo->exec('DROP DATABASE IF EXISTS `' . $database . '`');

            $left = $pdo->query("SHOW DATABASES LIKE 'vidyalms\\_r13\\_%'")->fetchAll(PDO::FETCH_COLUMN);
            out("\nthrowaway databases left: " . \count($left));
        }
    }

    step('Result');

    foreach ($notes as $note) {
        out('  note: ' . $note);
    }

    if ($failures !== []) {
        foreach ($failures as $failure) {
            out('  FAILED: ' . $failure);
        }

        out("\nREFUSED");
        exit(1);
    }

    out("\nPASS");
    exit(0);
}
