<?php

declare(strict_types=1);

/**
 * VidyaLMS: what MySQL through wpdb does differently.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

defined('ABSPATH') || exit;

use Shondalai\VidyaLMS\Core\Port\Database\Sql\SqlDialect;
use wpdb;

/**
 * What WordPress's SQL does differently, and nothing else.
 *
 * Compiling a Query is SqlQueryCompiler's, shared with the Joomla adapter and
 * the test harness: the condition compiler with its ORed group, the LIKE
 * escape, the conditional COUNT, the AVG rate and the nulls-last leading key
 * were the same code written out three times, and the port contract proved
 * they agreed only after the fact.
 *
 * The decisions left here are genuinely WordPress's: backticks quote,
 * $wpdb->prefix names the table, prepare() has only positional placeholders
 * whose spelling carries the type, and MySQL needs a doubled backslash inside
 * a string literal for LIKE's escape character.
 */
final class WpSqlDialect implements SqlDialect
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /** @var list<scalar> */
    private array $arguments = [];

    public function __construct(
        private readonly wpdb $wpdb,
        private readonly string $tablePrefix,
        private readonly string $datetimeFormat,
    ) {
    }

    /** The pattern admits no backtick, so wrapping in backticks is complete. */
    public function quoteIdentifier(string $identifier): string
    {
        if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
            // Exception arguments are data; escape at the HTTP/template boundary.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \InvalidArgumentException(sprintf('Unsupported identifier "%s".', $identifier));
        }

        return '`' . $identifier . '`';
    }

    /**
     * A host table belongs to another plugin ("wp_learndash_..."), so it takes
     * WordPress's own prefix and none of ours. Only a Query::fromHostTable()
     * read reaches that branch; every write stays inside our own tables.
     */
    public function physicalTable(string $table, bool $hostTable): string
    {
        if (preg_match(self::IDENTIFIER, $table) !== 1) {
            // Exception arguments are data; escape at the HTTP/template boundary.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \InvalidArgumentException(sprintf('Unsupported table name "%s".', $table));
        }

        // These source importers combine per-blog course IDs with global
        // course_N usermeta. Refuse their installation probes and all reads
        // on a network until source metadata ownership is proven per site.
        if ($hostTable && (str_starts_with($table, 'learndash_') || str_starts_with($table, 'tutor_'))
            && is_multisite()) {
            throw new \RuntimeException('Local LearnDash and Tutor LMS database imports are unavailable on multisite.');
        }

        $prefix = $hostTable && in_array($table, ['users', 'usermeta'], true) ? $this->wpdb->base_prefix : $this->wpdb->prefix;
        return '`' . $prefix . ($hostTable ? '' : $this->tablePrefix) . $table . '`';
    }

    /**
     * Appends the value to the argument list and returns the placeholder that
     * carries its type. A null becomes the SQL keyword and takes no argument:
     * prepare() has no null placeholder and would write an empty string for
     * %s or a zero for %d.
     *
     * Order matters: prepare() has only positional placeholders, so the
     * compiler builds a fragment at the moment its arguments are appended and
     * never moves it afterwards.
     */
    public function placeholder(mixed $value): string
    {
        $value = $this->normalise($value);

        if ($value === null) {
            return 'NULL';
        }

        if (\is_int($value)) {
            $this->arguments[] = $value;

            return '%d';
        }

        if (\is_float($value)) {
            $this->arguments[] = $value;

            // prepare() rewrites %f to %F so the decimal separator stays a
            // point whatever locale the site runs under.
            return '%f';
        }

        $this->arguments[] = (string) $value;

        // prepare() adds the quotes around %s itself.
        return '%s';
    }

    /** The two backslashes are one backslash to MySQL's string parser. */
    public function likeEscape(): string
    {
        return " ESCAPE '\\\\'";
    }

    /**
     * Query validates both as zero or positive integers, so they are written
     * into the statement rather than bound: a placeholder in a LIMIT clause is
     * quoted as a string by prepare() when it is not reached in argument
     * order.
     */
    public function limitClause(?int $limit, int $offset): string
    {
        if ($limit !== null) {
            return $offset > 0 ? ' LIMIT ' . $limit . ' OFFSET ' . $offset : ' LIMIT ' . $limit;
        }

        // MySQL has no bare OFFSET, so a limit has to be stated; this one is
        // larger than any row count a site will reach.
        return $offset > 0 ? ' LIMIT 18446744073709551615 OFFSET ' . $offset : '';
    }

    /**
     * @return list<scalar>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    private function normalise(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format($this->datetimeFormat);
        }

        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }
}
