<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Schema;

/**
 * Loads and validates the neutral schema in schema/tables.php. Every table
 * gets an integer primary key "id" and, unless disabled, "created_at" and
 * "updated_at" datetimes. Compilers for Joomla SQL, WordPress dbDelta and
 * the SQLite test harness all read this one definition.
 */
final class SchemaDefinition
{
    /**
     * The index key limit of the legacy COMPACT and REDUNDANT InnoDB row
     * formats. DYNAMIC raises it to 3072, but a site's row format is the
     * host's business and not this schema's, so the floor is what binds.
     */
    private const MAX_UNIQUE_INDEX_BYTES = 767;

    /**
     * The three unique indexes that were declared before the limit was
     * asserted, each named with the byte width it declares:
     *
     *   import_map.import_map_importer_source_type_source_key_uq  1160
     *   idempotency_keys.idempotency_keys_scope_idem_key_uq       1160
     *   question_banks.question_banks_owner_name_uq (Pro)         1104
     *
     * They are installed on live sites under DYNAMIC row format, where they
     * work, and narrowing one is a migration with a uniqueness change in it,
     * not a schema edit. Each is a candidate for the digest column treatment
     * `entitlements.source_ref_digest` already uses. Nothing may be added to
     * this list: a new wide unique index is the case the assertion exists for.
     *
     * @var list<string>
     */
    private const WIDE_UNIQUE_INDEXES = [
        'import_map.import_map_importer_source_type_source_key_uq',
        'idempotency_keys.idempotency_keys_scope_idem_key_uq',
        'question_banks.question_banks_owner_name_uq',
    ];

    /**
     * @param array<string, Table> $tables keyed by logical table name
     */
    private function __construct(
        public readonly array $tables,
    ) {
    }

    public static function load(?string $path = null): self
    {
        $path ??= \dirname(__DIR__, 2) . '/schema/tables.php';

        if (!is_file($path)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \LogicException(sprintf('Schema file "%s" does not exist.', $path));
        }

        // Before the file is loaded, because PHP resolves a duplicate table key
        // itself and the loaded array cannot show that one declaration is gone.
        SchemaSource::assertNoDuplicateTableKeys($path);

        /** @var mixed $definitions */
        $definitions = require $path;

        if (!\is_array($definitions)) {
            throw new \LogicException('The schema file must return an array of table definitions.');
        }

        // The scan above reads one shape. This is where that assumption stops
        // being an assumption: a file it could not read reports no duplicates,
        // which is the one answer a refusal must never give quietly.
        SchemaSource::assertScanCovers($path, $definitions);

        return self::fromArray($definitions);
    }

    /**
     * The validating constructor every loader goes through, and the one a test
     * can reach with a single table.
     *
     * @param array<mixed> $definitions
     */
    public static function fromArray(array $definitions): self
    {
        $tables = [];

        /** @var mixed $definition */
        foreach ($definitions as $tableName => $definition) {
            if (!\is_string($tableName) || !\is_array($definition)) {
                throw new \LogicException('Table definitions must be keyed by table name.');
            }

            $tables[$tableName] = self::buildTable($tableName, $definition);
        }

        foreach ($tables as $table) {
            self::assertIndexWidths($table);
        }

        return new self($tables);
    }

    public function table(string $name): Table
    {
        return $this->tables[$name]
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw new \LogicException(sprintf('The schema defines no table "%s".', $name));
    }

    /**
     * @return list<string>
     */
    public function tableNames(): array
    {
        return array_keys($this->tables);
    }

    /**
     * @param array<mixed> $definition
     */
    private static function buildTable(string $tableName, array $definition): Table
    {
        $columnSpecs = $definition['columns'] ?? null;

        if (!\is_array($columnSpecs)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \LogicException(sprintf('Table "%s" needs a "columns" array.', $tableName));
        }

        $timestamps = $definition['timestamps'] ?? true;
        $columns = [];
        $columns['id'] = new Column('id', ColumnType::Identifier);

        /** @var mixed $spec */
        foreach ($columnSpecs as $columnName => $spec) {
            if (!\is_string($columnName)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \LogicException(sprintf('Table "%s": columns must be keyed by name.', $tableName));
            }

            if (\in_array($columnName, ['id', 'created_at', 'updated_at'], true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \LogicException(sprintf('Table "%s": column "%s" is added automatically.', $tableName, $columnName));
            }

            if (\is_string($spec)) {
                $columns[$columnName] = Column::fromSpec($columnName, $spec);
                continue;
            }

            if (\is_array($spec) && isset($spec[0]) && \is_string($spec[0])) {
                $hasDefault = \array_key_exists('default', $spec);
                /** @var string|int|float|bool|null $default */
                $default = $spec['default'] ?? null;

                $columns[$columnName] = Column::fromSpec($columnName, $spec[0], $hasDefault, $default);
                continue;
            }

            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \LogicException(sprintf('Table "%s": column "%s" has an invalid spec.', $tableName, $columnName));
        }

        if ($timestamps === true) {
            $columns['created_at'] = new Column('created_at', ColumnType::DateTime);
            $columns['updated_at'] = new Column('updated_at', ColumnType::DateTime);
        }

        $indexes = [];
        $indexSpecs = $definition['indexes'] ?? [];

        if (!\is_array($indexSpecs)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \LogicException(sprintf('Table "%s": "indexes" must be an array.', $tableName));
        }

        /** @var mixed $indexSpec */
        foreach ($indexSpecs as $position => $indexSpec) {
            if (!\is_array($indexSpec) || !isset($indexSpec['columns']) || !\is_array($indexSpec['columns'])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \LogicException(sprintf('Table "%s": every index needs a "columns" list.', $tableName));
            }

            /** @var list<string> $indexColumns */
            $indexColumns = array_values($indexSpec['columns']);

            foreach ($indexColumns as $indexColumn) {
                if (!isset($columns[$indexColumn])) {
                    throw new \LogicException(sprintf(
                        'Table "%s": index references unknown column "%s".',
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        $tableName,
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        $indexColumn,
                    ));
                }
            }

            $unique = (bool) ($indexSpec['unique'] ?? false);
            $name = \is_string($indexSpec['name'] ?? null)
                ? $indexSpec['name']
                : sprintf('%s_%s_%s', $tableName, implode('_', $indexColumns), $unique ? 'uq' : 'ix');

            $indexes[] = new Index($name, $indexColumns, $unique);

            unset($position);
        }

        return new Table($tableName, $columns, $indexes);
    }

    /**
     * A unique index whose declared columns exceed 767 bytes under utf8mb4 is
     * refused, so the next one is caught at build time rather than on a
     * customer's host running a COMPACT or REDUNDANT InnoDB row format. The
     * remedy is the one this schema already uses in several places: carry the
     * uniqueness on a char(64) SHA-256 digest column and keep the natural
     * columns on a plain index.
     *
     * The width is now computed for **every** index rather than only a unique
     * one, because the json and text refusal lives inside that computation and
     * MySQL rejects a plain KEY over one of those exactly as it rejects a
     * UNIQUE KEY. Only the 767 byte refusal itself is still conditional on
     * uniqueness: narrowing a unique index changes what the database
     * guarantees, so it is a migration and not a schema edit.
     *
     * Two shipped plain indexes are over that floor and are recorded here
     * rather than discovered later, both the same (source, source_ref) pair:
     *
     *   entitlements.entitlements_source_source_ref_ix        880
     *   cohort_members.cohort_members_source_source_ref_ix    840 (Pro)
     *
     * Both install on the DYNAMIC row format the two development hosts use.
     * WidePlainIndexCensusTest pins that the shipped schemas contain exactly
     * those two, so a third becomes visible in a build rather than in a
     * customer's install log.
     */
    private static function assertIndexWidths(Table $table): void
    {
        foreach ($table->indexes as $index) {
            $width = 0;

            foreach ($index->columns as $columnName) {
                $width += self::indexByteWidth($table, $index, $columnName);
            }

            if (!$index->unique || $width <= self::MAX_UNIQUE_INDEX_BYTES) {
                continue;
            }

            if (\in_array($table->name . '.' . $index->name, self::WIDE_UNIQUE_INDEXES, true)) {
                continue;
            }

            throw new \LogicException(sprintf(
                'Table "%s": unique index "%s" declares %d bytes under utf8mb4 and the limit is %d. '
                . 'Carry the uniqueness on a string:64 SHA-256 digest column and keep (%s) on a plain index.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $table->name,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $index->name,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $width,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::MAX_UNIQUE_INDEX_BYTES,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', $index->columns),
            ));
        }
    }

    private static function indexByteWidth(Table $table, Index $index, string $columnName): int
    {
        $column = $table->column($columnName);

        return match ($column->type) {
            ColumnType::Text => 4 * (int) $column->length,
            ColumnType::Identifier,
            ColumnType::Integer,
            ColumnType::UnsignedInteger => 4,
            ColumnType::BigInteger,
            ColumnType::Float => 8,
            ColumnType::Boolean => 1,
            ColumnType::DateTime => 5,
            ColumnType::Date => 3,
            ColumnType::Decimal => 4 * (int) ceil(((int) $column->precision) / 9),
            // MySQL cannot index one of these without a prefix length and this
            // grammar has no way to declare one.
            ColumnType::Json,
            ColumnType::LongText,
            ColumnType::MediumText => throw new \LogicException(sprintf(
                'Table "%s": index "%s" names "%s", which is a %s column and needs a prefix length no schema '
                . 'spec can express.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $table->name,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $index->name,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $columnName,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $column->type->value,
            )),
        };
    }
}
