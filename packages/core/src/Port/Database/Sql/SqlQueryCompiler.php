<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Database\Sql;

use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Turns a Query into SQL, once, for every host.
 *
 * It emits fragments as well as a whole statement, because the hosts differ
 * in how they assemble one: WordPress and the SQLite harness concatenate a
 * string, while Joomla feeds a QueryInterface that owns its own prefix
 * replacement and binding. Both paths run the same condition compiler, the
 * same LIKE escape, the same conditional COUNT and rate, and the same
 * nulls-last leading key, which is the whole point: the three copies of that
 * code agreed only because a contract test checked them afterwards.
 *
 * Identifiers arriving here have already been validated by the read port
 * (Identifier, called from every Query builder), and each dialect quotes
 * again on its own side. That is defence in depth rather than the defence.
 */
final class SqlQueryCompiler
{
    private const PLAIN = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const QUALIFIED = '/^([A-Za-z_][A-Za-z0-9_]*)\.([A-Za-z_][A-Za-z0-9_]*|\*)$/';
    private const RENAMED = '/^(.+?) +AS +([A-Za-z_][A-Za-z0-9_]*)$/i';

    public function __construct(private readonly SqlDialect $dialect)
    {
    }

    /**
     * One entry of a select list, a join key, a group by or an order by:
     * "*", "t.*", "title", "c.title", or "c.title AS course_title".
     */
    public function columnExpression(string $expression): string
    {
        if ($expression === '*') {
            return $expression;
        }

        if (preg_match(self::RENAMED, $expression, $renamed) === 1) {
            return $this->columnExpression($renamed[1])
                . ' AS ' . $this->dialect->quoteIdentifier($renamed[2]);
        }

        if (preg_match(self::PLAIN, $expression) === 1) {
            return $this->dialect->quoteIdentifier($expression);
        }

        if (preg_match(self::QUALIFIED, $expression, $parts) === 1) {
            $column = $parts[2] === '*' ? '*' : $this->dialect->quoteIdentifier($parts[2]);

            return $this->dialect->quoteIdentifier($parts[1]) . '.' . $column;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw new \InvalidArgumentException(sprintf('Unsupported column expression "%s".', $expression));
    }

    /**
     * The projection. A count wraps the select and counts its rows, so the
     * projection is dead weight there: "1" is cheaper and, with grouping,
     * still counts exactly the groups the caller would have read.
     */
    public function selectList(Query $query, bool $forCount = false): string
    {
        // A grouped count normally needs no projection. HAVING is the one
        // exception: it may name an aggregate alias, so the inner query must
        // still define that alias before the outer SELECT counts its rows.
        if ($forCount && $query->havingConditions() === []) {
            return '1';
        }

        $projection = array_map($this->columnExpression(...), $query->columns());

        foreach ($query->aggregates() as $aggregate) {
            $projection[] = $this->aggregateExpression($aggregate);
        }

        return implode(', ', $projection);
    }

    /** The table and its alias, without the FROM keyword. */
    public function fromClause(Query $query): string
    {
        $from = $this->dialect->physicalTable($query->table(), $query->readsHostTable());

        return $query->alias() === ''
            ? $from
            : $from . ' AS ' . $this->dialect->quoteIdentifier($query->alias());
    }

    /**
     * @return list<array{type: string, table: string, alias: string, on: string}>
     */
    public function joins(Query $query): array
    {
        $hostTable = $query->readsHostTable();
        $joins = [];

        foreach ($query->joins() as $join) {
            $on = $this->columnExpression($join['first']) . ' = ' . $this->columnExpression($join['second']);

            foreach ($join['also'] as [$first, $second]) {
                $on .= ' AND ' . $this->columnExpression($first) . ' = ' . $this->columnExpression($second);
            }

            $joins[] = [
                'type' => $join['type'] === 'LEFT' ? 'LEFT' : 'INNER',
                'table' => $this->dialect->physicalTable($join['table'], $hostTable),
                'alias' => $this->dialect->quoteIdentifier($join['alias']),
                'on' => $on,
            ];
        }

        return $joins;
    }

    /**
     * @return list<string>
     */
    public function whereClauses(Query $query): array
    {
        $clauses = [];

        foreach ($query->conditions() as $condition) {
            $clauses[] = $this->conditionClause($condition);
        }

        return $clauses;
    }

    /**
     * @return list<string>
     */
    public function groupBy(Query $query): array
    {
        return array_map($this->columnExpression(...), $query->grouping());
    }

    /** @return list<string> */
    public function havingClauses(Query $query): array
    {
        return array_map($this->conditionClause(...), $query->havingConditions());
    }

    /**
     * @return list<string>
     */
    public function orderBy(Query $query): array
    {
        $orders = [];

        foreach ($query->order() as $order) {
            $column = $this->columnExpression($order['column']);

            // Neither MySQL nor MariaDB has NULLS LAST, so it is an extra
            // leading key: rows with a value sort 0 and rows without sort 1,
            // ascending, whichever way the real key then runs. The column may
            // be an aggregate alias, which ORDER BY accepts.
            if ($order['nulls_last']) {
                $orders[] = '(' . $column . ' IS NULL) ASC';
            }

            $orders[] = $column . ' ' . $order['direction'];
        }

        return $orders;
    }

    /**
     * The whole SELECT, for a host that assembles a statement rather than a
     * query object.
     */
    public function statement(Query $query, bool $forCount = false): string
    {
        $sql = 'SELECT ' . $this->selectList($query, $forCount) . ' FROM ' . $this->fromClause($query);

        foreach ($this->joins($query) as $join) {
            $sql .= sprintf(' %s JOIN %s AS %s ON %s', $join['type'], $join['table'], $join['alias'], $join['on']);
        }

        $where = $this->whereClauses($query);

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $group = $this->groupBy($query);

        if ($group !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $group);
        }

        $having = $this->havingClauses($query);

        if ($having !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $having);
        }

        if ($forCount) {
            return $sql;
        }

        $order = $this->orderBy($query);

        if ($order !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $order);
        }

        return $sql . $this->dialect->limitClause($query->limitValue(), $query->offsetValue());
    }

    /**
     * A conjunction of conditions, for the CASE inside an aggregate.
     *
     * @param list<array{kind: string, column: string, operator: string, value: mixed}> $conditions
     */
    public function conjunction(array $conditions): string
    {
        $clauses = [];

        foreach ($conditions as $condition) {
            $clauses[] = $this->conditionClause($condition);
        }

        return $clauses === [] ? '1 = 1' : implode(' AND ', $clauses);
    }

    /**
     * One condition of a read.
     *
     * Every condition the core can express compiles here, wherever it came
     * from: the query's own WHERE list, an OR group, or the CASE inside an
     * aggregate. One compiler means an OR group and a conditional count
     * escape a LIKE term exactly as a top level WHERE does.
     *
     * @param array{kind: string, column: string, operator: string, value: mixed} $condition
     */
    public function conditionClause(array $condition): string
    {
        if ($condition['kind'] === Predicate::ANY || $condition['kind'] === Predicate::ALL) {
            /** @var list<array{kind: string, column: string, operator: string, value: mixed}> $members */
            $members = $condition['value'];
            $clauses = [];

            foreach ($members as $member) {
                $clauses[] = $this->conditionClause($member);
            }

            // Empty groups cannot be built, so this is always at least one
            // clause. Nested groups recurse through this same safe compiler.
            $separator = $condition['kind'] === Predicate::ANY ? ' OR ' : ' AND ';

            return '(' . implode($separator, $clauses) . ')';
        }

        $column = $this->columnExpression($condition['column']);

        switch ($condition['kind']) {
            case Predicate::COMPARE:
                return $column . ' ' . $condition['operator'] . ' ' . $this->dialect->placeholder($condition['value']);

            case Predicate::CONTAINS:
            case Predicate::STARTS_WITH:
                // The term is data, never pattern: the LIKE metacharacters are
                // escaped and the escape character is stated, so "50%" matches
                // the text 50% and a bare "%" matches nothing extra.
                $term = ($condition['kind'] === Predicate::CONTAINS ? '%' : '')
                    . addcslashes((string) $condition['value'], '\\%_') . '%';

                return $column . ' LIKE ' . $this->dialect->placeholder($term) . $this->dialect->likeEscape();

            case Predicate::IN:
                /** @var list<scalar> $members */
                $members = $condition['value'];

                if ($members === []) {
                    return '1 = 0';
                }

                $placeholders = [];

                foreach ($members as $member) {
                    $placeholders[] = $this->dialect->placeholder($member);
                }

                return $column . ' IN (' . implode(', ', $placeholders) . ')';

            case Predicate::NULL:
                return $column . ' IS NULL';

            case Predicate::NOT_NULL:
                return $column . ' IS NOT NULL';

            default:
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \LogicException(sprintf('Unknown condition kind "%s".', $condition['kind']));
        }
    }

    /**
     * One aggregate column, under its alias.
     *
     * A conditional count counts the rows whose CASE yields a value; the rows
     * that do not match yield NULL and are not counted, which is what makes a
     * LEFT JOIN that matched nothing count zero rather than one.
     *
     * A rate is an AVG over 1.0 and 0.0, so an empty domain averages nothing
     * and answers NULL: a course nobody is enrolled in has no completion rate,
     * and zero would claim a failure it has not earned.
     *
     * The domain of a rate is compiled twice, once per CASE arm, rather than
     * once and reused. Repeating a named placeholder in one statement is a
     * question about the driver's prepare emulation, and this boundary does
     * not ask questions it can avoid.
     */
    public function aggregateExpression(Aggregate $aggregate): string
    {
        $alias = $this->dialect->quoteIdentifier($aggregate->alias());

        if ($aggregate->isRate()) {
            $domain = $this->conjunction($aggregate->over());
            $match = $this->conjunction($aggregate->when());
            $again = $this->conjunction($aggregate->over());

            return 'AVG(CASE WHEN (' . $domain . ') AND (' . $match . ') THEN 1.0'
                . ' WHEN (' . $again . ') THEN 0.0 END) AS ' . $alias;
        }

        if ($aggregate->isConditional()) {
            return 'COUNT(CASE WHEN (' . $this->conjunction($aggregate->when()) . ') THEN 1 END) AS ' . $alias;
        }

        $column = $aggregate->column() === Aggregate::ALL
            ? '*'
            : $this->columnExpression($aggregate->column());

        if ($aggregate->function() === Aggregate::COUNT_DISTINCT) {
            return 'COUNT(DISTINCT ' . $column . ') AS ' . $alias;
        }

        return $aggregate->function() . '(' . $column . ') AS ' . $alias;
    }
}
