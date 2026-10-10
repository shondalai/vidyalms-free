<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Schema\Column;
use Shondalai\VidyaLMS\Core\Schema\ColumnType;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;
use Shondalai\VidyaLMS\Core\Schema\Table;

/**
 * Compiles the neutral schema to SQLite DDL and creates every table. The
 * production compilers (Joomla SQL, WordPress dbDelta) live in tooling/ and
 * read the same definition.
 */
final class SqliteSchemaInstaller
{
    public static function install(SqliteDatabase $database, ?SchemaDefinition $schema = null): void
    {
        $schema ??= SchemaDefinition::load();

        foreach ($schema->tables as $table) {
            foreach (self::statementsFor($table) as $statement) {
                $database->pdo()->exec($statement);
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function statementsFor(Table $table): array
    {
        $physical = SqliteDatabase::PREFIX . $table->name;
        $columnSql = [];

        foreach ($table->columns as $column) {
            $columnSql[] = self::columnSql($column);
        }

        $statements = [sprintf(
            'CREATE TABLE "%s" (%s)',
            $physical,
            implode(', ', $columnSql),
        )];

        foreach ($table->indexes as $index) {
            $statements[] = sprintf(
                'CREATE %sINDEX "%s" ON "%s" (%s)',
                $index->unique ? 'UNIQUE ' : '',
                $physical . '_' . $index->name,
                $physical,
                implode(', ', array_map(static fn (string $column): string => '"' . $column . '"', $index->columns)),
            );
        }

        return $statements;
    }

    private static function columnSql(Column $column): string
    {
        if ($column->type === ColumnType::Identifier) {
            return '"id" INTEGER PRIMARY KEY AUTOINCREMENT';
        }

        $type = match ($column->type) {
            ColumnType::Integer, ColumnType::UnsignedInteger, ColumnType::BigInteger, ColumnType::Boolean => 'INTEGER',
            ColumnType::Decimal, ColumnType::Float => 'REAL',
            default => 'TEXT',
        };

        $sql = '"' . $column->name . '" ' . $type;

        if (!$column->nullable) {
            $sql .= ' NOT NULL';
        }

        if ($column->hasDefault) {
            $sql .= ' DEFAULT ' . self::defaultLiteral($column->default);
        }

        return $sql;
    }

    private static function defaultLiteral(string|int|float|bool|null $default): string
    {
        if ($default === null) {
            return 'NULL';
        }

        if (\is_bool($default)) {
            return $default ? '1' : '0';
        }

        if (\is_int($default) || \is_float($default)) {
            return (string) $default;
        }

        return "'" . str_replace("'", "''", $default) . "'";
    }
}
