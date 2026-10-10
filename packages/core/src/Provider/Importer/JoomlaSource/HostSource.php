<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Every read a Joomla source importer makes goes through here.
 *
 * The three products this base serves (Shika, Guru, OSCampus) keep their
 * tables in the same database as VidyaLMS, so their rows come back through
 * the same DatabasePort the rest of the core uses, named as host tables
 * (Query::fromHostTable) so the adapter resolves them with the host prefix
 * and none of ours: "oscampus_courses" becomes "#__oscampus_courses" on
 * Joomla. Only reads exist here, and only reads are possible: the port's
 * write methods have no host form.
 *
 * ## The table list is closed and it is a constant
 *
 * tables() is a fixed list declared by the subclass as class constants. A
 * table name never comes from an option, a selector or a row, because a name
 * that came from input would be a name an operator could point anywhere in
 * the site's database, and because the list is the contract with a foreign
 * schema: naming a table is saying "this importer knows what this is". read()
 * refuses anything outside the list with a LogicException, which is a
 * programming error rather than an operator's.
 *
 * ## Confirmed shapes are listed; unconfirmed shapes are not
 *
 * Where the product's schema is published and this importer was written from
 * it, the columns are listed explicitly at each call site, because the list is
 * the contract with a foreign schema and a column the importer does not read
 * is a column the product may rename without breaking the migration.
 *
 * Where the shape could not be confirmed from any published source, the read
 * selects every column and the mapper reads the keys it wants with documented
 * alternatives. That is the opposite of the rule above and it is deliberate:
 * writing a guessed column name into a SELECT list turns a guess into a hard
 * failure of the whole table, so a single wrong guess would lose a site their
 * entire question bank rather than one field of it. Every such table says so
 * in its own docblock, and the class docblock of each importer lists them.
 *
 * Ordering follows from the same split. A confirmed table is ordered by the
 * column the product documents; an unconfirmed one is ordered in PHP by
 * ordered(), because an ORDER BY on a column that is not there fails the read
 * exactly as a SELECT of it would.
 *
 * ## Reading is allowed to fail, and one table failing is not a run failing
 *
 * These importers are written from published documentation and are
 * unverified against a real install, so a missing table or a renamed column
 * is the expected failure rather than the impossible one. Every read is
 * wrapped: a driver error becomes SourceUnreadable naming the table and the
 * columns it wanted, which the importer turns into one honest note and then
 * carries on with the tables it can read.
 */
abstract class HostSource
{
    public function __construct(
        private readonly DatabasePort $database,
    ) {
    }

    /**
     * Whether this product looks installed on the host database: one of its
     * tables answers a single row read.
     *
     * It is a probe rather than a schema query because the core has no
     * portable "does this table exist" and may not invent one out of the
     * host's information schema. Any failure at all means "not here", which
     * is the honest answer for detection: a table that cannot be read is a
     * table this importer cannot import from, whatever the reason.
     */
    public function has(string $table): bool
    {
        if (!\in_array($table, $this->tables(), true)) {
            return false;
        }

        try {
            $this->database->selectOne(Query::fromHostTable($table)->select(['id'])->limit(1));
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    /**
     * The tables this importer may read, and the only ones it may read.
     *
     * @return list<string>
     */
    abstract protected function tables(): array;

    /**
     * A read of one table of the closed list.
     */
    final protected function read(string $table): Query
    {
        if (!\in_array($table, $this->tables(), true)) {
            throw new \LogicException(sprintf(
                '"%s" is not one of the tables %s declares. A source table name is a constant of the'
                    . ' importer, never a value that reached it from outside.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $table,
                static::class,
            ));
        }

        return Query::fromHostTable($table);
    }

    /**
     * @param list<string> $columns the columns the query asked for, for the refusal
     *
     * @return list<array<string, mixed>>
     */
    final protected function all(Query $query, array $columns): array
    {
        try {
            return $this->database->selectAll($query);
        } catch (\Throwable $failure) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw SourceUnreadable::of($query->table(), $columns, $failure);
        }
    }

    /**
     * @param list<string> $columns
     *
     * @return array<string, mixed>|null
     */
    final protected function one(Query $query, array $columns): ?array
    {
        try {
            return $this->database->selectOne($query);
        } catch (\Throwable $failure) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw SourceUnreadable::of($query->table(), $columns, $failure);
        }
    }

    /**
     * Rows of an unconfirmed table in the order the product most likely shows
     * them: by an "ordering" column when the rows carry one, and by id
     * otherwise, with id as the tie-break either way.
     *
     * It sorts here rather than in SQL because a table whose shape is not
     * confirmed may not have the column, and an ORDER BY on a column that is
     * not there fails the whole read. Sorting a page of curriculum rows in
     * PHP costs nothing; losing the curriculum costs the migration.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    final protected function ordered(array $rows): array
    {
        usort($rows, static function (array $left, array $right): int {
            $byOrder = self::number($left['ordering'] ?? null) <=> self::number($right['ordering'] ?? null);

            return $byOrder !== 0 ? $byOrder : self::number($left['id'] ?? null) <=> self::number($right['id'] ?? null);
        });

        return $rows;
    }

    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
