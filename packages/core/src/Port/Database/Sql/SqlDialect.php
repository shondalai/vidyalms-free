<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Database\Sql;

/**
 * The handful of things that genuinely differ between one host's SQL and
 * another's.
 *
 * Everything else about compiling a Query is a property of Query, not of
 * Joomla or WordPress, and used to be written out three times: the condition
 * compiler with its ORed group, the LIKE metacharacter escape, the
 * conditional COUNT(CASE WHEN), the AVG(CASE ... 1.0/0.0) rate and the
 * nulls-last leading key were the same code in JoomlaDatabase, WpDatabase and
 * the SQLite test harness. The port contract proved the three agreed after
 * the fact but shared none of the code, so a compilation bug was three edits
 * and the contract test was what stood between the fix and two of them being
 * forgotten.
 *
 * An implementation is about thirty lines and owns four decisions: how an
 * identifier is quoted, how a logical table name becomes a physical one, how
 * a bound value is registered and written, and how LIKE states its escape
 * character.
 */
interface SqlDialect
{
    public function quoteIdentifier(string $identifier): string;

    /**
     * The physical name of a table. $hostTable is true for a Query built with
     * fromHostTable(), which names a table the host CMS owns and takes the
     * host prefix alone.
     */
    public function physicalTable(string $table, bool $hostTable): string;

    /**
     * Registers one bound value and answers the placeholder text that stands
     * for it. Every host binds; only the spelling differs (":c3" on Joomla,
     * "%s" on WordPress, "?" on the SQLite harness), and the order of the
     * calls is the order the values are bound in.
     */
    public function placeholder(mixed $value): string;

    /**
     * What follows a LIKE to state its escape character, including the
     * leading space, or an empty string where the host needs none.
     *
     * It is a dialect decision because a backslash is itself an escape inside
     * a MySQL string literal and is not inside a SQLite one, so the same
     * intent is written differently.
     */
    public function likeEscape(): string;

    /**
     * LIMIT and OFFSET as this host writes them, including the leading space,
     * or an empty string when the host applies them through its own query
     * object rather than through the statement text.
     */
    public function limitClause(?int $limit, int $offset): string;
}
