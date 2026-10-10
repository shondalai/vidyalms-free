<?php

declare(strict_types=1);

/**
 * VidyaLMS: what the database must actually look like after dbDelta ran.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Setup;

defined('ABSPATH') || exit;

/**
 * The post-condition of an install, asked of the database rather than of
 * dbDelta.
 *
 * dbDelta reports the changes it *planned*, not the ones the server accepted.
 * It alters a table and says nothing when the alter did not happen, and the
 * failure that matters most is the quiet one: a rejected ADD KEY leaves the
 * columns perfectly usable, lets the data migration succeed, and lets the
 * caller record a version whose paging indexes do not exist. The site then
 * works until it is large enough not to.
 *
 * Until R13 the free plugin had a different defence for this: the deleted
 * WordPress chain carried a hundred and nine SELECT shape probes across
 * seventy nine tables, every one of which ran on a fresh install because
 * pending() owes a site with no recorded version every file. The chain went
 * and the probes went with it, which left the free install path asserting
 * nothing at all after dbDelta while the Pro one kept its own equivalent.
 * This is that post-condition restored, and it is the Pro implementation
 * (Pro\WordPress\Setup\Schema::expectedShape and ::assertInstalledShape)
 * ported rather than a second algorithm invented beside it.
 *
 * It is a separate copy in each plugin on purpose. Pro names no free-build
 * class anywhere in Setup, because Pro must survive a request where the free
 * plugin's files are simply not on disk; see Pro\WordPress\Setup\Requirements
 * for the same rule stated at the other end. A shared class would be a fatal
 * on exactly the request that check exists to make survivable.
 *
 * Two reads per table, both metadata. Extra columns and extra indexes are
 * accepted: a site carrying something the compiled schema no longer declares
 * is repaired by hand, not by destructive DDL at activation.
 */
final class SchemaShape
{
    /**
     * Keep equivalent server type spellings out of dbDelta's ALTER list.
     * New columns retain the authored type, including MariaDB JSON validation.
     * Signedness and real type/length changes remain visible to dbDelta.
     *
     * @param list<string> $statements
     * @return list<string>
     */
    public static function forComparison(array $statements): array
    {
        global $wpdb;
        $maria = stripos($wpdb->db_server_info(), 'MariaDB') !== false;
        foreach ($statements as &$statement) {
            if (preg_match('/^CREATE TABLE\s+`?([A-Za-z0-9_]+)`?\s*\(/i', $statement, $match) !== 1) {
                continue;
            }
            $previous = $wpdb->suppress_errors();
            try {
                // Installation compares current server metadata with the shipped schema; caching would hide incomplete upgrades.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $rows = $wpdb->get_results($wpdb->prepare('SHOW COLUMNS FROM %i', $match[1]), 'ARRAY_A');
            } finally {
                $wpdb->suppress_errors($previous);
            }
            $types = [];
            foreach ((array) $rows as $row) {
                $types[strtolower($row['Field'])] = strtolower($row['Type']);
            }
            $statement = preg_replace_callback(
                '/^(\s*`([^`]+)`\s+)([a-z]+(?:\(\d+\))?(?: unsigned)?)/im',
                static function (array $parts) use ($types, $maria): string {
                    $expected = strtolower($parts[3]);
                    $actual = $types[strtolower($parts[2])] ?? '';
                    $withoutWidth = static fn (string $type): string => preg_replace(
                        '/^(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $type
                    ) ?? $type;
                    if (($actual !== '' && $withoutWidth($actual) === $withoutWidth($expected))
                        || ($maria && $expected === 'json' && $actual === 'longtext')) {
                        return $parts[1] . $actual;
                    }
                    return $parts[0];
                },
                $statement,
            ) ?? $statement;
        }
        unset($statement);
        return $statements;
    }

    private function __construct()
    {
    }

    /**
     * Prove the database holds every table, column and named index the
     * compiled statements declare, or throw naming what is missing.
     *
     * @param list<string> $statements dbDelta-adapted CREATE TABLE statements
     *
     * @throws \RuntimeException when the installed shape is not the declared one
     */
    public static function assertInstalled(array $statements): void
    {
        global $wpdb;

        $problems = [];

        foreach (self::expected($statements) as $table => $expected) {
            /** @var mixed $columnRows */
            // Schema verification needs current metadata for our own tables, not cached content.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $columnRows = $wpdb->get_results($wpdb->prepare('SHOW COLUMNS FROM %i', $table), 'ARRAY_A');
            /** @var mixed $indexRows */
            // Schema verification needs current metadata for our own tables, not cached content.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $indexRows = $wpdb->get_results($wpdb->prepare('SHOW INDEX FROM %i', $table), 'ARRAY_A');

            if (!\is_array($columnRows) || !\is_array($indexRows)) {
                $detail = trim((string) ($wpdb->last_error ?? ''));
                $problems[] = $table . ' metadata could not be read' . ($detail === '' ? '' : ': ' . $detail);

                continue;
            }

            $actualColumns = [];

            foreach ($columnRows as $row) {
                if (!\is_array($row) || !isset($row['Field']) || !\is_string($row['Field'])) {
                    continue;
                }

                $actualColumns[strtolower($row['Field'])] = true;
            }

            $missingColumns = array_values(array_filter(
                $expected['columns'],
                static fn (string $column): bool => !isset($actualColumns[$column]),
            ));

            if ($missingColumns !== []) {
                $problems[] = $table . ' missing column(s) ' . implode(', ', $missingColumns);
            }

            $actualIndexes = self::actualIndexes($indexRows);

            foreach ($expected['indexes'] as $name => $expectedIndex) {
                $actual = $actualIndexes[$name] ?? null;

                if ($actual === null) {
                    $problems[] = $table . ' missing index ' . $name;

                    continue;
                }

                if ($actual['columns'] !== $expectedIndex['columns'] || $actual['unique'] !== $expectedIndex['unique']) {
                    $problems[] = $table . ' index ' . $name . ' has the wrong columns or uniqueness';
                }
            }
        }

        if ($problems !== []) {
            throw new \RuntimeException(
                // Exception arguments are data; escape at the HTTP/template boundary.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                'The installed VidyaLMS schema does not match the compiled schema: ' . implode('; ', $problems)
            );
        }
    }

    /**
     * SHOW INDEX rows folded into one entry per named index.
     *
     * @param array<array-key, mixed> $indexRows
     *
     * @return array<string, array{columns: list<string>, unique: bool}>
     */
    private static function actualIndexes(array $indexRows): array
    {
        /** @var array<string, array{columns: array<int, string>, unique: bool}> $indexes */
        $indexes = [];

        foreach ($indexRows as $row) {
            if (
                !\is_array($row)
                || !isset($row['Key_name'], $row['Seq_in_index'], $row['Column_name'], $row['Non_unique'])
                || !\is_string($row['Key_name'])
                || !\is_string($row['Column_name'])
            ) {
                continue;
            }

            $name = strtolower($row['Key_name']);
            $sequence = (int) $row['Seq_in_index'];

            if ($sequence < 1) {
                continue;
            }

            $indexes[$name] ??= ['columns' => [], 'unique' => (int) $row['Non_unique'] === 0];
            $indexes[$name]['columns'][$sequence] = strtolower($row['Column_name']);
        }

        $folded = [];

        foreach ($indexes as $name => $index) {
            ksort($index['columns']);

            $folded[$name] = ['columns' => array_values($index['columns']), 'unique' => $index['unique']];
        }

        return $folded;
    }

    /**
     * Read the required shape from the compiler output rather than maintaining
     * a second hand-written list that can drift at the next schema release.
     *
     * @param list<string> $statements
     *
     * @return array<string, array{columns: list<string>, indexes: array<string, array{columns: list<string>, unique: bool}>}>
     */
    public static function expected(array $statements): array
    {
        $shape = [];

        foreach ($statements as $statement) {
            if (preg_match('/^CREATE TABLE\s+`?([A-Za-z0-9_]+)`?\s*\((.*)\)\s*(?:ENGINE\b.*)?$/is', trim($statement), $tableParts) !== 1) {
                throw new \RuntimeException('The compiled VidyaLMS schema contains an unsupported table statement.');
            }

            $table = $tableParts[1];
            $columns = [];
            $indexes = [];

            foreach (preg_split('/\R/', $tableParts[2]) ?: [] as $rawLine) {
                $line = rtrim(trim($rawLine), ',');

                if ($line === '') {
                    continue;
                }

                if (preg_match('/^`([^`]+)`\s+/', $line, $columnParts) === 1) {
                    $columns[] = strtolower($columnParts[1]);

                    continue;
                }

                if (preg_match('/^PRIMARY KEY\s+\((.+)\)$/i', $line, $indexParts) === 1) {
                    $indexes['primary'] = [
                        'columns' => self::indexColumns($indexParts[1]),
                        'unique' => true,
                    ];

                    continue;
                }

                if (preg_match('/^(UNIQUE\s+)?(?:KEY|INDEX)\s+`([^`]+)`\s+\((.+)\)$/i', $line, $indexParts) === 1) {
                    $indexes[strtolower($indexParts[2])] = [
                        'columns' => self::indexColumns($indexParts[3]),
                        'unique' => trim((string) $indexParts[1]) !== '',
                    ];

                    continue;
                }

                // Exception arguments are data; escape at the HTTP/template boundary.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new \RuntimeException('The compiled VidyaLMS schema contains an unsupported table clause in ' . $table . '.');
            }

            if ($columns === [] || !isset($indexes['primary'])) {
                // Exception arguments are data; escape at the HTTP/template boundary.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                throw new \RuntimeException('The compiled VidyaLMS schema has an incomplete table definition for ' . $table . '.');
            }

            $shape[$table] = ['columns' => array_values(array_unique($columns)), 'indexes' => $indexes];
        }

        if ($shape === []) {
            throw new \RuntimeException('The compiled VidyaLMS schema declares no tables.');
        }

        return $shape;
    }

    /** @return list<string> */
    private static function indexColumns(string $definition): array
    {
        $columns = [];

        foreach (preg_split('/\s*,\s*/', trim($definition)) ?: [] as $part) {
            if (preg_match('/^`([^`]+)`(?:\(\d+\))?(?:\s+(?:ASC|DESC))?$/i', trim($part), $columnParts) !== 1) {
                throw new \RuntimeException('The compiled VidyaLMS schema contains an unsupported index column.');
            }

            $columns[] = strtolower($columnParts[1]);
        }

        if ($columns === []) {
            throw new \RuntimeException('The compiled VidyaLMS schema contains an empty index.');
        }

        return $columns;
    }

}
