<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Database;

/**
 * A small, safe query description for reads. Repositories build these against
 * logical table names ("courses", "enrolments"); the adapter maps logical names
 * to physical, prefixed tables and compiles conditions with bound parameters.
 *
 * Deliberately minimal: equality-style filters, IN, NULL checks, LIKE, one
 * grouped AND/OR predicates, aggregate columns, ordering (null aware), grouping, bound
 * comparisons in HAVING and pagination. Anything richer belongs in a
 * scheduled report job, never in a request path.
 *
 * Aggregates and null-aware ordering exist for one reason: a list that sorts
 * on a count or on a nullable date has to sort and cut its page at the
 * database, or it reads the whole filtered set into PHP on every request. See
 * Aggregate for the three shapes and Predicate for the conditions they carry.
 *
 * A query built with fromHostTable() names a table the host CMS owns rather
 * than one of ours, and the adapter resolves it with the host prefix alone
 * ("#__cq_courses" on Joomla, "wp_learndash_..." on WordPress). That is what
 * a "cms-db:" importer reads, and it is read only by construction: the write
 * methods of DatabasePort take a logical table name and have no host form, so
 * the core can never write a table another extension owns.
 */
final class Query
{
    public const SORT_ASC = 'ASC';
    public const SORT_DESC = 'DESC';

    private const OPERATORS = ['=', '!=', '<', '<=', '>', '>=', 'LIKE'];

    /** @var list<string> */
    private array $columns = ['*'];

    /** @var list<Aggregate> */
    private array $aggregates = [];

    /**
     * @var list<array{
     *     type: string,
     *     table: string,
     *     alias: string,
     *     first: string,
     *     second: string,
     *     also: list<array{string, string}>
     * }>
     */
    private array $joins = [];

    /** @var list<array{kind: string, column: string, operator: string, value: mixed}> */
    private array $conditions = [];

    /** @var list<array{kind: string, column: string, operator: string, value: mixed}> */
    private array $having = [];

    /** @var list<array{column: string, direction: string, nulls_last: bool}> */
    private array $orderBy = [];

    /** @var list<string> */
    private array $groupBy = [];

    private ?int $limit = null;

    private int $offset = 0;

    private bool $hostTable = false;

    private function __construct(
        private readonly string $table,
        private readonly string $alias,
    ) {
        Identifier::table($table);

        if ($alias !== '') {
            Identifier::alias($alias);
        }
    }

    /**
     * @param list<array{string, string}> $also
     *
     * @return array{
     *     type: string,
     *     table: string,
     *     alias: string,
     *     first: string,
     *     second: string,
     *     also: list<array{string, string}>
     * }
     */
    private function joinOn(
        string $type,
        string $table,
        string $alias,
        string $first,
        string $second,
        array $also = [],
    ): array {
        $extra = [];

        foreach ($also as [$left, $right]) {
            $extra[] = [Identifier::column($left), Identifier::column($right)];
        }

        return [
            'type' => $type,
            'table' => Identifier::table($table),
            'alias' => Identifier::alias($alias),
            'first' => Identifier::column($first),
            'second' => Identifier::column($second),
            'also' => $extra,
        ];
    }

    public static function from(string $table, string $alias = ''): self
    {
        return new self($table, $alias);
    }

    /**
     * Reads a table of the host CMS: the name is resolved with the host
     * prefix and nothing of ours. Every table this query touches, joins
     * included, is read as a host table.
     */
    public static function fromHostTable(string $table, string $alias = ''): self
    {
        $query = new self($table, $alias);
        $query->hostTable = true;

        return $query;
    }

    /**
     * @param list<string> $columns
     */
    public function select(array $columns): self
    {
        if ($this->aggregates !== [] && $columns === ['*']) {
            throw new \InvalidArgumentException('A query carrying aggregates must select its plain columns explicitly.');
        }

        $this->columns = array_map(Identifier::selection(...), $columns);

        return $this;
    }

    /**
     * Adds aggregate columns to the result. Each one is selected under its
     * alias, which the query may then order on.
     *
     * The plain columns must be named explicitly first: "*" beside an
     * aggregate is a query whose meaning depends on how forgiving the host's
     * sql_mode is, so it is refused here rather than being answered
     * differently on MySQL, MariaDB and SQLite. Group by whatever plain
     * columns you select.
     */
    public function aggregate(Aggregate ...$aggregates): self
    {
        if ($this->columns === ['*']) {
            throw new \InvalidArgumentException('Select the plain columns explicitly before adding an aggregate.');
        }

        foreach ($aggregates as $aggregate) {
            $this->aggregates[] = $aggregate;
        }

        return $this;
    }

    /**
     * A join on one column pair, plus any further pairs ANDed onto the same
     * ON clause.
     *
     * The extra pairs exist because a composite key is not a special case:
     * attempt_grades belongs to (attempt_id, question_id), and joining it on
     * the attempt alone pairs every served question with every grade row of
     * that attempt, which pooled one item's responses across every marking
     * rule used anywhere in the paper. Expressing the second column as a
     * where() cannot substitute, because a where() on a LEFT join's table
     * turns it into an inner one.
     *
     * @param list<array{string, string}> $also
     */
    public function join(string $table, string $alias, string $first, string $second, array $also = []): self
    {
        $this->joins[] = $this->joinOn('INNER', $table, $alias, $first, $second, $also);

        return $this;
    }

    /**
     * @param list<array{string, string}> $also
     */
    public function leftJoin(string $table, string $alias, string $first, string $second, array $also = []): self
    {
        $this->joins[] = $this->joinOn('LEFT', $table, $alias, $first, $second, $also);

        return $this;
    }

    public function where(string $column, string $operator, mixed $value): self
    {
        if (!\in_array($operator, self::OPERATORS, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unsupported operator "%s".', $operator));
        }

        $this->conditions[] = [
            'kind' => 'compare',
            'column' => Identifier::column($column),
            'operator' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * A literal substring match. The term is data, never pattern: adapters
     * must escape their LIKE metacharacters (%, _, the escape character)
     * before wrapping it in wildcards, so "50%" matches the text 50% and a
     * bare "%" matches nothing extra.
     */
    public function whereContains(string $column, string $term): self
    {
        $this->conditions[] = [
            'kind' => 'contains',
            'column' => Identifier::column($column),
            'operator' => 'LIKE',
            'value' => $term,
        ];

        return $this;
    }

    /** A literal prefix match, with LIKE metacharacters escaped by the compiler. */
    public function whereStartsWith(string $column, string $prefix): self
    {
        $this->conditions[] = [
            'kind' => Predicate::STARTS_WITH,
            'column' => Identifier::column($column),
            'operator' => 'LIKE',
            'value' => $prefix,
        ];

        return $this;
    }

    /**
     * @param list<scalar> $values
     */
    public function whereIn(string $column, array $values): self
    {
        $this->conditions[] = [
            'kind' => 'in',
            'column' => Identifier::column($column),
            'operator' => 'IN',
            'value' => array_values($values),
        ];

        return $this;
    }

    public function whereNull(string $column): self
    {
        $this->conditions[] = [
            'kind' => 'null',
            'column' => Identifier::column($column),
            'operator' => 'IS NULL',
            'value' => null,
        ];

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->conditions[] = [
            'kind' => 'notnull',
            'column' => Identifier::column($column),
            'operator' => 'IS NOT NULL',
            'value' => null,
        ];

        return $this;
    }

    /**
     * A group of conditions of which any one is enough, ANDed with the rest
     * of the query. A member may be Predicate::allOf(), which lets a caller
     * express a bounded list of compound identities without carrying SQL.
     */
    public function whereAnyOf(Predicate ...$predicates): self
    {
        if ($predicates === []) {
            throw new \InvalidArgumentException('An OR group needs at least one predicate.');
        }

        $this->conditions[] = [
            'kind' => Predicate::ANY,
            'column' => '',
            'operator' => 'OR',
            'value' => Predicate::toArrays(array_values($predicates)),
        ];

        return $this;
    }

    /**
     * Filters grouped results by an aggregate alias. Kept to comparisons so
     * values remain bound and the caller cannot smuggle an expression into
     * HAVING. This is the grouped counterpart of where().
     */
    public function having(string $aggregateAlias, string $operator, mixed $value): self
    {
        if (!\in_array($operator, self::OPERATORS, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unsupported operator "%s".', $operator));
        }

        $this->having[] = Predicate::compare($aggregateAlias, $operator, $value)->toArray();

        return $this;
    }

    /**
     * Orders on a column or on an aggregate alias.
     *
     * $nullsLast puts rows whose value is null after every row that has one,
     * in both directions, which is what "never expires" means in a list
     * sorted by expiry. Neither MySQL nor MariaDB has the NULLS LAST syntax,
     * so adapters compile it as an extra leading key ("column IS NULL"
     * ascending) rather than emitting SQL only SQLite and Postgres accept.
     * Left false, null ordering is the host default, which on every
     * supported host is nulls first ascending and nulls last descending.
     */
    public function orderBy(string $column, string $direction = self::SORT_ASC, bool $nullsLast = false): self
    {
        $direction = strtoupper($direction);

        if (!\in_array($direction, [self::SORT_ASC, self::SORT_DESC], true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unsupported sort direction "%s".', $direction));
        }

        $this->orderBy[] = [
            'column' => Identifier::column($column),
            'direction' => $direction,
            'nulls_last' => $nullsLast,
        ];

        return $this;
    }

    public function groupBy(string $column): self
    {
        $this->groupBy[] = Identifier::column($column);

        return $this;
    }

    public function limit(?int $limit): self
    {
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException('Limit must be zero or positive.');
        }

        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException('Offset must be zero or positive.');
        }

        $this->offset = $offset;

        return $this;
    }

    public function table(): string
    {
        return $this->table;
    }

    public function alias(): string
    {
        return $this->alias;
    }

    public function readsHostTable(): bool
    {
        return $this->hostTable;
    }

    /**
     * @return list<string>
     */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<Aggregate>
     */
    public function aggregates(): array
    {
        return $this->aggregates;
    }

    /**
     * @return list<array{
     *     type: string,
     *     table: string,
     *     alias: string,
     *     first: string,
     *     second: string,
     *     also: list<array{string, string}>
     * }>
     */
    public function joins(): array
    {
        return $this->joins;
    }

    /**
     * @return list<array{kind: string, column: string, operator: string, value: mixed}>
     */
    public function conditions(): array
    {
        return $this->conditions;
    }

    /**
     * @return list<array{column: string, direction: string, nulls_last: bool}>
     */
    public function order(): array
    {
        return $this->orderBy;
    }

    /**
     * @return list<string>
     */
    public function grouping(): array
    {
        return $this->groupBy;
    }

    /** @return list<array{kind: string, column: string, operator: string, value: mixed}> */
    public function havingConditions(): array
    {
        return $this->having;
    }

    public function limitValue(): ?int
    {
        return $this->limit;
    }

    public function offsetValue(): int
    {
        return $this->offset;
    }
}
