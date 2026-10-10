<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Database;

/**
 * One condition, as a value rather than as a call on a query.
 *
 * Query has always carried conditions as arrays because that is what an
 * adapter compiles. A condition is now needed in two more places where a
 * fluent method cannot reach: inside an aggregate (the CASE that decides
 * which rows it counts) and inside an OR group. Both take Predicates, and
 * both compile to exactly the array shape Query::conditions() already
 * returns, so an adapter has one condition compiler and not three.
 *
 * Columns are validated as identifiers here, values travel as bound
 * parameters, and there is no form that carries SQL text.
 */
final class Predicate
{
    public const COMPARE = 'compare';
    public const CONTAINS = 'contains';
    public const STARTS_WITH = 'starts_with';
    public const IN = 'in';
    public const NULL = 'null';
    public const NOT_NULL = 'notnull';

    /** The kind of an ORed group of predicates inside a conditions list. */
    public const ANY = 'any';

    /** The kind of an ANDed group nested inside another predicate group. */
    public const ALL = 'all';

    private const OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'LIKE'];

    /**
     * @param array{kind: string, column: string, operator: string, value: mixed} $condition
     */
    private function __construct(
        private readonly array $condition,
    ) {
    }

    public static function compare(string $column, string $operator, mixed $value): self
    {
        if (!\in_array($operator, self::OPERATORS, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unsupported operator "%s".', $operator));
        }

        return new self([
            'kind' => self::COMPARE,
            'column' => Identifier::column($column),
            'operator' => $operator,
            'value' => $value,
        ]);
    }

    /**
     * A literal substring match; the term is data, never pattern. Adapters
     * escape their own LIKE metacharacters, exactly as for Query::whereContains().
     */
    public static function contains(string $column, string $term): self
    {
        return new self([
            'kind' => self::CONTAINS,
            'column' => Identifier::column($column),
            'operator' => 'LIKE',
            'value' => $term,
        ]);
    }

    /**
     * @param list<scalar> $values
     */
    public static function in(string $column, array $values): self
    {
        return new self([
            'kind' => self::IN,
            'column' => Identifier::column($column),
            'operator' => 'IN',
            'value' => array_values($values),
        ]);
    }

    public static function isNull(string $column): self
    {
        return new self([
            'kind' => self::NULL,
            'column' => Identifier::column($column),
            'operator' => 'IS NULL',
            'value' => null,
        ]);
    }

    public static function isNotNull(string $column): self
    {
        return new self([
            'kind' => self::NOT_NULL,
            'column' => Identifier::column($column),
            'operator' => 'IS NOT NULL',
            'value' => null,
        ]);
    }

    /**
     * A conjunction that can be carried as one member of an OR group.
     *
     * This is deliberately a predicate tree rather than SQL text: it is the
     * safe shape needed for filters such as
     * `(owner_scope = course AND owner_id = 7) OR (...)`, while every value
     * still reaches the adapter as a bound parameter.
     */
    public static function allOf(self ...$predicates): self
    {
        if ($predicates === []) {
            throw new \InvalidArgumentException('An AND group needs at least one predicate.');
        }

        return new self([
            'kind' => self::ALL,
            'column' => '',
            'operator' => 'AND',
            'value' => self::toArrays(array_values($predicates)),
        ]);
    }

    /**
     * @return array{kind: string, column: string, operator: string, value: mixed}
     */
    public function toArray(): array
    {
        return $this->condition;
    }

    /**
     * @param list<self> $predicates
     *
     * @return list<array{kind: string, column: string, operator: string, value: mixed}>
     */
    public static function toArrays(array $predicates): array
    {
        return array_map(static fn (self $predicate): array => $predicate->toArray(), $predicates);
    }
}
