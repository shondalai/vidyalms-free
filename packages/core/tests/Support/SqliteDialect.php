<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Database\Sql\SqlDialect;

/**
 * What SQLite's SQL does differently, and nothing else. Everything about
 * compiling a Query is SqlQueryCompiler's, shared with both adapters, which
 * is what makes the port contract a proof rather than a coincidence.
 */
final class SqliteDialect implements SqlDialect
{
    /** @var list<mixed> */
    private array $bindings = [];

    public function __construct(private readonly string $prefix)
    {
    }

    public function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }

    /**
     * A host table keeps its own name, because that is what a host table is:
     * a test seeds "cq_courses" exactly as Community Quiz names it.
     */
    public function physicalTable(string $table, bool $hostTable): string
    {
        return $this->quoteIdentifier($hostTable ? $table : $this->prefix . $table);
    }

    public function placeholder(mixed $value): string
    {
        $this->bindings[] = self::normalise($value);

        return '?';
    }

    /**
     * A backslash is not itself an escape inside a SQLite string literal, so
     * one is written where MySQL needs two.
     */
    public function likeEscape(): string
    {
        return " ESCAPE '\'";
    }

    /**
     * SQLite has no bare OFFSET, so an offset with no limit takes the
     * documented -1.
     */
    public function limitClause(?int $limit, int $offset): string
    {
        if ($limit !== null) {
            return $offset > 0 ? ' LIMIT ' . $limit . ' OFFSET ' . $offset : ' LIMIT ' . $limit;
        }

        return $offset > 0 ? ' LIMIT -1 OFFSET ' . $offset : '';
    }

    /**
     * @return list<mixed>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    private static function normalise(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (\is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }
}
