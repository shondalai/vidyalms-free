<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Database;

/**
 * One aggregate column of a read: a function over a column, under an alias
 * the query can select, group by and order on.
 *
 * This exists so a list that sorts on a count sorts and pages at the
 * database. Without it the only way to order courses by their enrolment
 * count was to read every course, count in PHP, sort and slice, which is
 * fine at ten courses and is a full table scan per request at ten thousand.
 *
 * Four shapes:
 *
 * - a plain aggregate over a column: COUNT, SUM, MIN, MAX, AVG.
 * - a distinct count used when joins deliberately repeat a logical row.
 * - a conditional count, which counts only the rows matching its
 *   predicates: COUNT(CASE WHEN ... THEN 1 END). Rows that do not match
 *   contribute NULL and are not counted, so a LEFT JOIN that matched
 *   nothing counts zero rather than one.
 * - a rate, the fraction of the rows in a domain that also match a
 *   condition, between 0 and 1 and NULL when the domain is empty:
 *   AVG(CASE WHEN domain AND match THEN 1.0 WHEN domain THEN 0.0 END).
 *   The NULL is the point: a course nobody is enrolled in has no completion
 *   rate, and zero would claim a failure it has not earned.
 *
 * Every column and every alias is an identifier (see Identifier); every
 * value in a predicate is a bound parameter. No part of an aggregate is
 * ever SQL text, so nothing a caller passes can reach an adapter as syntax.
 */
final class Aggregate
{
    public const COUNT = 'COUNT';
    public const SUM = 'SUM';
    public const MIN = 'MIN';
    public const MAX = 'MAX';
    public const AVG = 'AVG';
    public const COUNT_DISTINCT = 'COUNT_DISTINCT';

    private const FUNCTIONS = [self::COUNT, self::SUM, self::MIN, self::MAX, self::AVG, self::COUNT_DISTINCT];

    /** The column of a COUNT(*). */
    public const ALL = '*';

    /**
     * @param list<array{kind: string, column: string, operator: string, value: mixed}> $when
     * @param list<array{kind: string, column: string, operator: string, value: mixed}> $over
     */
    private function __construct(
        private readonly string $function,
        private readonly string $column,
        private readonly string $alias,
        private readonly array $when,
        private readonly array $over,
        private readonly bool $rate,
    ) {
    }

    public static function count(string $alias): self
    {
        return new self(self::COUNT, self::ALL, Identifier::alias($alias), [], [], false);
    }

    public static function countOf(string $column, string $alias): self
    {
        return self::of(self::COUNT, $column, $alias);
    }

    public static function countDistinctOf(string $column, string $alias): self
    {
        return self::of(self::COUNT_DISTINCT, $column, $alias);
    }

    /**
     * @param list<Predicate> $when every predicate must hold for the row to count
     */
    public static function countWhere(array $when, string $alias): self
    {
        if ($when === []) {
            throw new \InvalidArgumentException('A conditional count needs at least one predicate.');
        }

        return new self(
            self::COUNT,
            self::ALL,
            Identifier::alias($alias),
            Predicate::toArrays($when),
            [],
            false,
        );
    }

    public static function sum(string $column, string $alias): self
    {
        return self::of(self::SUM, $column, $alias);
    }

    public static function min(string $column, string $alias): self
    {
        return self::of(self::MIN, $column, $alias);
    }

    public static function max(string $column, string $alias): self
    {
        return self::of(self::MAX, $column, $alias);
    }

    public static function avg(string $column, string $alias): self
    {
        return self::of(self::AVG, $column, $alias);
    }

    /**
     * The share of the rows matching $over that also match $match, and null
     * when nothing matches $over.
     *
     * @param list<Predicate> $over  the rows the rate is taken over
     * @param list<Predicate> $match the rows among them that count as a hit
     */
    public static function rate(array $over, array $match, string $alias): self
    {
        if ($over === [] || $match === []) {
            throw new \InvalidArgumentException('A rate needs both a domain and a match predicate.');
        }

        return new self(
            self::AVG,
            self::ALL,
            Identifier::alias($alias),
            Predicate::toArrays($match),
            Predicate::toArrays($over),
            true,
        );
    }

    private static function of(string $function, string $column, string $alias): self
    {
        if (!\in_array($function, self::FUNCTIONS, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unsupported aggregate function "%s".', $function));
        }

        return new self($function, Identifier::column($column), Identifier::alias($alias), [], [], false);
    }

    public function function(): string
    {
        return $this->function;
    }

    /**
     * The aggregated column, or "*" when the aggregate counts rows rather
     * than values.
     */
    public function column(): string
    {
        return $this->column;
    }

    public function alias(): string
    {
        return $this->alias;
    }

    /**
     * The predicates that decide which rows the aggregate sees, ANDed.
     * Empty for a plain aggregate.
     *
     * @return list<array{kind: string, column: string, operator: string, value: mixed}>
     */
    public function when(): array
    {
        return $this->when;
    }

    /**
     * The domain of a rate, ANDed. Empty for everything else.
     *
     * @return list<array{kind: string, column: string, operator: string, value: mixed}>
     */
    public function over(): array
    {
        return $this->over;
    }

    public function isRate(): bool
    {
        return $this->rate;
    }

    public function isConditional(): bool
    {
        return !$this->rate && $this->when !== [];
    }
}
