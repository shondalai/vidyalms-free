<?php

/**
 * VidyaLMS schema compiler.
 *
 * Reads a neutral schema and emits:
 *   - Joomla install and uninstall SQL ("#__vidyalms_" prefix)
 *   - WordPress dbDelta statements ("{prefix}vidyalms_" placeholder)
 *
 * Usage:
 *   php tooling/schema/compile.php [--schema=<file>] [--name=<basename>]
 *                                  [--out=<dir>]
 *
 * Defaults compile the free build's packages/core/schema/tables.php into
 * install.mysql.utf8.sql, uninstall.mysql.utf8.sql and schema.php. A second
 * package passes --schema and --name: Pro compiles
 * packages/pro-core/schema/tables.php with --name=pro, which writes
 * pro-install.mysql.utf8.sql beside the free build's file rather than over
 * it.
 *
 * Pro tables keep the same "vidyalms_" prefix as free ones. They are the
 * same database in the same namespace; what makes a table Pro's is which
 * package installs it and which code reads it, never its name.
 *
 * Default output: tooling/schema/out/. When the adapters gain their final
 * directory layout, the packagers point --out at them.
 */

declare(strict_types=1);

use Shondalai\VidyaLMS\Core\Schema\Column;
use Shondalai\VidyaLMS\Core\Schema\ColumnType;
use Shondalai\VidyaLMS\Core\Schema\Index;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;
use Shondalai\VidyaLMS\Core\Schema\Table;

require dirname(__DIR__, 2) . '/packages/core/vendor/autoload.php';

/**
 * @return string MySQL column DDL
 */
function mysqlColumn(Column $column): string
{
    if ($column->type === ColumnType::Identifier) {
        return '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT';
    }

    $type = match ($column->type) {
        ColumnType::Integer => 'INT',
        ColumnType::UnsignedInteger => 'INT UNSIGNED',
        ColumnType::BigInteger => 'BIGINT',
        ColumnType::Boolean => 'TINYINT(1)',
        ColumnType::Text => sprintf('VARCHAR(%d)', (int) $column->length),
        ColumnType::LongText => 'TEXT',
        ColumnType::MediumText => 'MEDIUMTEXT',
        ColumnType::Json => 'JSON',
        ColumnType::DateTime => 'DATETIME',
        ColumnType::Date => 'DATE',
        ColumnType::Decimal => sprintf('DECIMAL(%d,%d)', (int) $column->precision, (int) $column->scale),
        ColumnType::Float => 'DOUBLE',
        ColumnType::Identifier => throw new LogicException('Unreachable.'),
    };

    $sql = sprintf('`%s` %s', $column->name, $type);
    $sql .= $column->nullable ? ' NULL' : ' NOT NULL';

    if ($column->hasDefault) {
        $default = $column->default;

        if ($default === null) {
            $sql .= ' DEFAULT NULL';
        } elseif (is_bool($default)) {
            $sql .= ' DEFAULT ' . ($default ? '1' : '0');
        } elseif (is_int($default) || is_float($default)) {
            $sql .= ' DEFAULT ' . $default;
        } else {
            $sql .= " DEFAULT '" . str_replace("'", "''", $default) . "'";
        }
    }

    return $sql;
}

function mysqlIndex(Index $index): string
{
    $columns = implode('`, `', $index->columns);

    return sprintf(
        '%s `%s` (`%s`)',
        $index->unique ? 'UNIQUE KEY' : 'KEY',
        $index->name,
        $columns,
    );
}

function mysqlCreateTable(Table $table, string $prefix, bool $ifNotExists): string
{
    $lines = [];

    foreach ($table->columns as $column) {
        $lines[] = '  ' . mysqlColumn($column);
    }

    $lines[] = '  PRIMARY KEY (`id`)';

    foreach ($table->indexes as $index) {
        $lines[] = '  ' . mysqlIndex($index);
    }

    // dbDelta keys statements by the token after "CREATE TABLE"; an
    // "IF NOT EXISTS" there makes it read "IF" as every table's name and
    // collapse all statements into one. WordPress output must stay plain.
    return sprintf(
        "CREATE TABLE %s`%svidyalms_%s` (\n%s\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
        $ifNotExists ? 'IF NOT EXISTS ' : '',
        $prefix,
        $table->name,
        implode(",\n", $lines),
    );
}

$root = dirname(__DIR__, 2);
$outDir = $root . '/tooling/schema/out';
$schemaPath = $root . '/packages/core/schema/tables.php';
$name = '';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--out=')) {
        $outDir = substr($arg, 6);
    } elseif (str_starts_with($arg, '--schema=')) {
        $schemaPath = substr($arg, 9);
    } elseif (str_starts_with($arg, '--name=')) {
        $name = substr($arg, 7);
    } else {
        fwrite(STDERR, "Unknown argument {$arg}\n");
        exit(1);
    }
}

if (!is_file($schemaPath)) {
    fwrite(STDERR, "No schema at {$schemaPath}\n");
    exit(1);
}

if ($name !== '' && !preg_match('/^[a-z0-9-]+$/', $name)) {
    fwrite(STDERR, "A name is a lowercase identifier, got {$name}\n");
    exit(1);
}

// How the banner names its own source and the command that rebuilds it.
$sourcePath = str_replace('\\', '/', (string) realpath($schemaPath));
$rootPath = str_replace('\\', '/', (string) realpath($root)) . '/';
$source = str_starts_with($sourcePath, $rootPath) ? substr($sourcePath, strlen($rootPath)) : $schemaPath;

$command = 'php tooling/schema/compile.php';

if ($source !== 'packages/core/schema/tables.php') {
    $command .= ' --schema=' . $source;
}

if ($name !== '') {
    $command .= ' --name=' . $name;
}

$filePrefix = $name === '' ? '' : $name . '-';

// SchemaDefinition::load() refuses a schema that declares a table twice and a
// schema whose unique index is too wide for a legacy InnoDB row format. Both
// are build failures rather than fatals here: the packagers run this script and
// report its exit code, and a stack trace says less than the message does.
try {
    $schema = SchemaDefinition::load($schemaPath);
} catch (\LogicException $refusal) {
    fwrite(STDERR, 'The schema was refused: ' . $refusal->getMessage() . "\n");
    exit(1);
}

/**
 * The host trees this compiler writes, as a list rather than as two named
 * variables.
 *
 * The emitters below are per host format and not per host: `mysql` writes the
 * two SQL files a Joomla extension installs and uninstalls with, and `wpdelta`
 * writes the dbDelta array a WordPress plugin activates with. A third host on
 * either format is one entry here, which is the difference between adding a
 * target and hand-writing every CREATE TABLE block again and proving it
 * yourself. A third host on a third format is a third emitter, and that is
 * honest work rather than a copied tree.
 *
 * @var array<string, array{format: string}>
 */
$targets = [
    'joomla' => ['format' => 'mysql'],
    'wordpress' => ['format' => 'wpdelta'],
];

$targetDirs = [];

foreach (array_keys($targets) as $target) {
    $dir = $outDir . '/' . $target;

    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        fwrite(STDERR, "Cannot create {$dir}\n");
        exit(1);
    }

    $targetDirs[$target] = $dir;
}

$joomlaDir = $targetDirs['joomla'];
$wordpressDir = $targetDirs['wordpress'];

$banner = "-- VidyaLMS schema, generated from {$source}.\n-- Do not edit by hand; run: {$command}\n\n";

// Joomla install: "#__" is replaced by Joomla with the site prefix.
$install = $banner;

foreach ($schema->tables as $table) {
    $install .= mysqlCreateTable($table, '#__', true) . "\n\n";
}

file_put_contents($joomlaDir . '/' . $filePrefix . 'install.mysql.utf8.sql', $install);

/**
 * The two tables an uninstall must not drop.
 *
 * notification_consents is append-only evidence that exists because the
 * burden of proof sits with the controller, and a deleted row is
 * indistinguishable both from never having consented and from never having
 * objected. notification_suppressions is a deliverability fact about an
 * address that binds every message class, so dropping it silently resurrects
 * every hard-bounced and every complained address, which is the fastest
 * available way to burn a site's sender reputation.
 *
 * An uninstall and reinstall cycle is an ordinary thing a site owner does,
 * and neither of these should survive it as data loss. A site owner who
 * genuinely wants them gone drops them by hand.
 */
const UNINSTALL_KEEPS = [
    'notification_consents',
    'notification_suppressions',
];

$uninstall = $banner;

foreach (array_reverse($schema->tableNames()) as $tableName) {
    if (in_array($tableName, UNINSTALL_KEEPS, true)) {
        $uninstall .= sprintf(
            "-- `#__vidyalms_%s` is deliberately NOT dropped: it is legally load-bearing\n"
            . "-- evidence (consent) or a deliverability fact about an address (suppression),\n"
            . "-- and an uninstall that destroyed either would lose an audit record and\n"
            . "-- resurrect every bounced and complained address on the next install.\n",
            $tableName,
        );

        continue;
    }

    $uninstall .= sprintf("DROP TABLE IF EXISTS `#__vidyalms_%s`;\n", $tableName);
}

file_put_contents($joomlaDir . '/' . $filePrefix . 'uninstall.mysql.utf8.sql', $uninstall);

// WordPress: one PHP file returning dbDelta-ready statements; the adapter
// substitutes {prefix} with $wpdb->prefix and appends the charset collate.
$statements = [];

foreach ($schema->tables as $table) {
    $statements[] = mysqlCreateTable($table, '{prefix}', false);
}

$export = "<?php\n\n"
    . "/**\n"
    . " * VidyaLMS dbDelta statements, generated from {$source}.\n"
    . " * Do not edit by hand; run: {$command}\n"
    . " * The adapter replaces {prefix} with \$wpdb->prefix before calling dbDelta().\n"
    . " */\n\n"
    . "return " . var_export($statements, true) . ";\n";

file_put_contents($wordpressDir . '/' . $filePrefix . 'schema.php', $export);

printf(
    "Compiled %d tables from %s.\n  %s\n  %s\n  %s\n",
    count($schema->tables),
    $source,
    $joomlaDir . '/' . $filePrefix . 'install.mysql.utf8.sql',
    $joomlaDir . '/' . $filePrefix . 'uninstall.mysql.utf8.sql',
    $wordpressDir . '/' . $filePrefix . 'schema.php',
);
