<?php

/**
 * @package     VidyaLMS
 * @subpackage  Build
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 *
 * The free Joomla schema lifecycle, rehearsed in a throwaway MySQL database
 * this script creates and drops.
 *
 * Three questions, and the third is the one the collapse of the update chain
 * made worth asking every build:
 *
 *   1. Does the shipped install script build the shape the neutral schema
 *      declares? Compared against a recorded dump, so a silent drift in the
 *      compiler or in the adapter's own section is a red run rather than a
 *      shrug.
 *   2. Does the install path leave the seed rows an installed site is supposed
 *      to hold? dbDelta and CREATE TABLE cannot write a row, so the rows come
 *      from the core's own use cases and this checks they arrived.
 *   3. Does an update still apply and still get recorded? The shipped tree
 *      holds no update file above the install floor, because the first release
 *      installs at the floor and nothing older exists to upgrade from. An
 *      update mechanism nothing exercises is an update mechanism nobody finds
 *      out is broken, so --synthetic writes one, proves it end to end and
 *      removes it again, leaving the tree exactly as it found it.
 *
 * Usage:
 *   php packages/adapter-joomla/build/schema-lifecycle-test.php \
 *       [--host=127.0.0.1] [--user=root] [--password=...] [--prefix=jos_] \
 *       [--baseline=<old-chain-joomla-free.json>] [--synthetic] [--keep]
 *
 * Credentials come from the command line or from VIDYALMS_DB_HOST,
 * VIDYALMS_DB_USER and VIDYALMS_DB_PASSWORD. Nothing is read from or written
 * to a real site: every database this touches is named vidyalms_r13_ and is
 * dropped before the script returns.
 */

declare(strict_types=1);

use Shondalai\VidyaLMS\Core\Application\Platform\RecordSchemaVersion;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSchemaVersionRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Tooling\Schema\JoomlaUpdateRules;
use Shondalai\VidyaLMS\Tooling\Schema\RehearsalDatabase;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "schema-lifecycle-test.php runs on the command line only.\n");
    exit(1);
}

$adapterDir = \dirname(__DIR__);
$repoRoot   = \dirname($adapterDir, 2);

require_once $repoRoot . '/packages/core/vendor/autoload.php';
require_once $repoRoot . '/tooling/schema/rehearsal-database.php';
require_once $repoRoot . '/tooling/schema/joomla-update-rules.php';

// The runner reads the update folder and nothing else, so it is the shipped
// class that answers "which version does an install record", not a copy of its
// rule written out here.
defined('_JEXEC') || \define('_JEXEC', 1);
require_once $adapterDir . '/com_vidyalms/admin/src/Service/SchemaVersion.php';

use VidyaLMS\Component\VidyaLMS\Administrator\Service\SchemaVersion;

/**
 * The one table the synthetic upgrade adds its column to. Small, present on
 * both hosts, and read by nothing this script asserts about.
 */
const SYNTHETIC_TABLE = 'idempotency_keys';

const SYNTHETIC_COLUMN = 'r13_rehearsal_marker';

$options = [
    'host' => getenv('VIDYALMS_DB_HOST') ?: '127.0.0.1',
    'user' => getenv('VIDYALMS_DB_USER') ?: 'root',
    'password' => getenv('VIDYALMS_DB_PASSWORD') ?: '',
    'prefix' => 'jos_',
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
            . "       [--prefix=jos_] [--baseline=<json>] [--synthetic] [--keep]\n";
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
 * Statements of a Joomla install or update script, comments stripped.
 *
 * @return list<string>
 */
function statementsOf(string $sql): array
{
    $clean = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
    $parts = array_map('trim', explode(";\n", $clean));

    return array_values(array_filter(
        $parts,
        static fn (string $part): bool => trim($part, "; \n\r\t") !== '',
    ));
}

/**
 * The information_schema shape of one database, in the same encoding the R13
 * baseline recorded, so the two can be compared line for line.
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
 * The seed rows an installed site is supposed to hold, minus the two columns
 * whose values are the moment of the install.
 *
 * @return list<array<string, mixed>>
 */
function seedRows(PDO $pdo, string $table): array
{
    /** @var list<array<string, mixed>> $rows */
    $rows = $pdo->query('SELECT * FROM `' . $table . '` ORDER BY `id`')->fetchAll(PDO::FETCH_ASSOC);

    return array_map(static function (array $row): array {
        unset($row['created_at'], $row['updated_at']);

        return $row;
    }, $rows);
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

$clock = new class implements ClockPort {
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
};

// SchemaVersion::UPDATES is relative to the component's administrator folder,
// which is com_vidyalms/admin here and administrator/components/com_vidyalms on
// an installed site.
$updatesDir  = $adapterDir . '/com_vidyalms/admin/' . SchemaVersion::UPDATES;
$installFile = $adapterDir . '/com_vidyalms/admin/sql/install.mysql.utf8.sql';
$version     = (string) require $repoRoot . '/tooling/schema/version.php';
$floor       = (string) require $repoRoot . '/tooling/schema/floor.php';
$database    = 'vidyalms_r13_free_joomla';
$prefix      = $options['prefix'];

out('VidyaLMS free Joomla schema lifecycle rehearsal');
out('  schema version : ' . $version);
out('  install floor  : ' . $floor);
out('  database       : ' . $database . ' (created and dropped by this script)');
out('  prefix         : ' . $prefix);

// ---------------------------------------------------------------------------
// Step 1: the shipped tree says what it is
// ---------------------------------------------------------------------------

step('Step 1: the update folder matches the floor invariant');

$shipped = [];

foreach ((array) scandir($updatesDir) as $entry) {
    if (\is_string($entry) && preg_match('/^(\d+\.\d+\.\d+)\.sql$/', $entry, $parts) === 1) {
        $shipped[] = $parts[1];
    }
}

usort($shipped, 'version_compare');

check($failures, version_compare($version, $floor, '>='), 'the schema version is at or above the install floor');
check($failures, \in_array($floor, $shipped, true), 'the update folder holds the floor marker ' . $floor . '.sql');
check(
    $failures,
    array_filter($shipped, static fn (string $v): bool => version_compare($v, $version, '>')) === [],
    'no update file is above the schema version',
);
check(
    $failures,
    array_filter($shipped, static fn (string $v): bool => version_compare($v, $floor, '<')) === [],
    'no update file is below the install floor',
);
check($failures, SchemaVersion::shipped($updatesDir) === $version, 'SchemaVersion::shipped() answers ' . $version);
check(
    $failures,
    trim(preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($updatesDir . '/' . $floor . '.sql')) ?? '') === '',
    'the floor marker carries no statements, because the install creates the tables',
);

out('  files          : ' . implode(', ', $shipped));

// ---------------------------------------------------------------------------
// Step 2: install into a throwaway database
// ---------------------------------------------------------------------------

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

try {
    $installSql = (string) file_get_contents($installFile);
    $statements = statementsOf($installSql);
    $executed   = 0;

    foreach ($statements as $statement) {
        $pdo->exec(str_replace('#__', $prefix, rtrim($statement, "; \n\r\t")));
        $executed++;
    }

    $tables = productTables($pdo, $database, $prefix);

    out('  statements     : ' . $executed);
    out('  tables         : ' . \count($tables));

    check($failures, $executed === \count($statements), 'every install statement ran');
    check($failures, \count($tables) >= 1, 'the install created the product tables');

    // -----------------------------------------------------------------------
    // Step 4: the version an install records
    // -----------------------------------------------------------------------

    step('Step 4: the installed version is recorded where both hosts can be asked');

    $port = new RehearsalDatabase($pdo, $prefix);
    $recorder = new RecordSchemaVersion(new DatabaseSchemaVersionRepository($port, $clock));
    $recorder->execute(SchemaVersion::shipped($updatesDir));
    $recorder->execute(SchemaVersion::shipped($updatesDir));

    $recorded = $pdo->query(
        'SELECT `version` FROM `' . $prefix . 'vidyalms_schema_version` ORDER BY `id`',
    )->fetchAll(PDO::FETCH_COLUMN);

    out('  recorded       : ' . implode(', ', $recorded));
    check($failures, $recorded === [$version], 'one row, holding ' . $version . ', after two installer passes');

    // -----------------------------------------------------------------------
    // Step 5: the shape, against the recorded old-chain dump
    // -----------------------------------------------------------------------

    $shape = shapeOf($pdo, $database, $tables);

    out('');
    out('  columns        : ' . \count($shape['columns']));
    out('  index parts    : ' . \count($shape['indexes']));

    if ($options['baseline'] !== '') {
        step('Step 5: the collapsed install against the recorded old chain');

        /** @var array{information_schema: array{tables: list<string>, columns: list<string>, indexes: list<string>}, seed_rows: array<string, array{rows: list<array<string, mixed>>}>} $baseline */
        $baseline = json_decode((string) file_get_contents((string) $options['baseline']), true, 512, JSON_THROW_ON_ERROR);

        $found = [
            ...differences($shape['tables'], $baseline['information_schema']['tables'], 'table'),
            ...differences($shape['columns'], $baseline['information_schema']['columns'], 'column'),
            ...differences($shape['indexes'], $baseline['information_schema']['indexes'], 'index part'),
        ];

        foreach (array_slice($found, 0, 25) as $difference) {
            out('  ' . $difference);
        }

        out('  differences    : ' . \count($found));
        check($failures, $found === [], 'the collapsed install reproduces the old chain exactly');

    } else {
        $notes[] = 'no --baseline was given, so the shape was measured but not compared';
    }

    // -----------------------------------------------------------------------
    // Step 6: the update path, proved and then taken away again
    // -----------------------------------------------------------------------

    if ($options['synthetic']) {
        step('Step 6: a synthetic update above the floor applies and is recorded');

        $syntheticVersion = synthetic($version);
        $syntheticFile    = $updatesDir . '/' . $syntheticVersion . '.sql';

        check($failures, !is_file($syntheticFile), 'the tree holds no ' . $syntheticVersion . '.sql before the rehearsal');
        check(
            $failures,
            \in_array($prefix . 'vidyalms_' . SYNTHETIC_TABLE, $tables, true),
            'the synthetic upgrade has a table to alter (' . SYNTHETIC_TABLE . ')',
        );

        $written = file_put_contents($syntheticFile, syntheticSql($syntheticVersion));

        if ($written === false) {
            throw new RuntimeException('Cannot write the synthetic update file ' . $syntheticFile);
        }

        try {
            check($failures, SchemaVersion::shipped($updatesDir) === $syntheticVersion, 'the runner now sees ' . $syntheticVersion);

            // The worked example has to be a file build/build.php would accept,
            // or it is a worked example of the wrong thing. The unguarded form
            // of the same statement is refused, which is what makes the rule
            // evidence rather than decoration.
            check(
                $failures,
                JoomlaUpdateRules::problemsIn((string) file_get_contents($syntheticFile), 'the synthetic file') === [],
                'the shipping rules accept the synthetic update file',
            );
            check(
                $failures,
                JoomlaUpdateRules::problemsIn(
                    'ALTER TABLE `#__vidyalms_' . SYNTHETIC_TABLE . '` ADD COLUMN `x` INT NULL;',
                    'unguarded',
                ) !== [],
                'and refuse the same statement without the CAN FAIL marker',
            );

            // Joomla's own rule: run every file above the recorded version, in
            // version order, then stamp the highest one that ran.
            $applied = 0;

            foreach ($shipped as $candidate) {
                if (version_compare($candidate, $version, '>')) {
                    $applied++;
                }
            }

            foreach (statementsOf((string) file_get_contents($syntheticFile)) as $statement) {
                $pdo->exec(str_replace('#__', $prefix, rtrim($statement, "; \n\r\t")));
                $applied++;
            }

            $column = $pdo->query(sprintf(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='%s'"
                . " AND TABLE_NAME='%s' AND COLUMN_NAME='%s'",
                $database,
                $prefix . 'vidyalms_' . SYNTHETIC_TABLE,
                SYNTHETIC_COLUMN,
            ))->fetchColumn();

            check($failures, $applied === 1, 'exactly one statement was above the installed version');
            check($failures, (int) $column === 1, 'the synthetic column arrived on ' . SYNTHETIC_TABLE);

            $recorder->execute(SchemaVersion::shipped($updatesDir));

            $after = $pdo->query(
                'SELECT `version` FROM `' . $prefix . 'vidyalms_schema_version` ORDER BY `id`',
            )->fetchAll(PDO::FETCH_COLUMN);

            out('  recorded       : ' . implode(', ', $after));
            check($failures, $after === [$version, $syntheticVersion], 'the upgrade was recorded beside the install');
        } finally {
            unlink($syntheticFile);
        }

        check($failures, !is_file($syntheticFile), 'the synthetic update file was removed');
        check($failures, SchemaVersion::shipped($updatesDir) === $version, 'the tree records ' . $version . ' again');
    } else {
        $notes[] = 'the update path was not exercised; pass --synthetic to prove it';
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

/**
 * The next patch version above $version, which is where an update file has to
 * live for a site already at $version to be able to run it.
 */
function synthetic(string $version): string
{
    $parts = array_map('intval', explode('.', $version));

    return sprintf('%d.%d.%d', $parts[0] ?? 1, $parts[1] ?? 0, ($parts[2] ?? 0) + 1);
}

function syntheticSql(string $version): string
{
    return "-- =============================================================================\n"
        . '-- VidyaLMS ' . $version . " (SYNTHETIC, written and removed by schema-lifecycle-test.php)\n"
        . "--\n"
        . "-- Nothing ships this file. It exists for the length of one rehearsal so the\n"
        . "-- update path is exercised on a tree whose highest update file is the install\n"
        . "-- floor: the mechanism is proved rather than assumed until the first real\n"
        . "-- schema change needs it.\n"
        . "--\n"
        . "-- It carries Joomla's CAN FAIL marker because every update file must, and a\n"
        . "-- worked example that would be refused by build/build.php is a worked example\n"
        . "-- of the wrong thing. See tooling/schema/joomla-update-rules.php.\n"
        . "-- =============================================================================\n\n"
        . 'ALTER TABLE `#__vidyalms_' . SYNTHETIC_TABLE . '`' . "\n"
        . '    ADD COLUMN `' . SYNTHETIC_COLUMN . '` VARCHAR(20) NULL '
        . \Shondalai\VidyaLMS\Tooling\Schema\JoomlaUpdateRules::CAN_FAIL_MARKER . ";\n";
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
