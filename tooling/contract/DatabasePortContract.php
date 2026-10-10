<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Contract;

use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\SupportsLockingReads;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;

/**
 * What every implementation of DatabasePort must answer identically:
 * SqliteDatabase in the core's test harness, JoomlaDatabase on Joomla,
 * WpDatabase on WordPress.
 *
 * It is a plain class rather than a PHPUnit test because two of the three
 * implementations only exist inside a booted CMS, where PHPUnit is not
 * available. The core wraps it in a test; each adapter's build/smoke.php
 * calls it against its own port. One body of assertions, three callers, so
 * "it works on SQLite" can never be the whole story again.
 *
 * Everything it writes happens inside a transaction it deliberately rolls
 * back, so a run against a live site leaves no row behind. It never creates
 * or drops a table.
 */
final class DatabasePortContract
{
    /** Marks every row this contract writes, so a failed rollback is visible. */
    public const MARKER = '__vidyalms_port_contract__';

    private const NOW = '2026-01-01 00:00:00';

    /**
     * The marker on the seeded enrolments. A title carries the course
     * marker, and an enrolment has no free text column to carry one, so a
     * reserved last_activity_at does the job: nothing real is stamped a
     * second after the epoch, no assertion here reads the column, and the
     * check does not depend on the courses surviving, which is the point
     * when the thing being checked is whether anything survived.
     */
    private const ENROLMENT_MARKER = '1970-01-01 00:00:01';

    /** @var list<string> */
    private array $failures = [];

    private function __construct(
        private readonly DatabasePort $database,
    ) {
    }

    /**
     * @return list<string> one line per broken expectation; empty means the
     *                      port satisfies the contract
     */
    public static function failures(DatabasePort $database): array
    {
        $contract = new self($database);

        try {
            $database->transactional(static function () use ($contract): void {
                $contract->run();

                throw new RollbackSignal();
            });
        } catch (RollbackSignal) {
            // The intended exit: the work is done and the transaction is gone.
        } catch (\Throwable $error) {
            $contract->failures[] = 'threw: ' . $error->getMessage();
        }

        $contract->assertNothingSurvived();

        return $contract->failures;
    }

    /**
     * Checked rather than assumed: a host whose transaction support quietly
     * did nothing would otherwise leave rows on a live site and report a
     * clean run.
     */
    private function assertNothingSurvived(): void
    {
        $courses = $this->database->count(Query::from('courses')->whereContains('title', self::MARKER));
        $enrolments = $this->database->count(
            Query::from('enrolments')->where('last_activity_at', '=', self::ENROLMENT_MARKER),
        );

        if ($courses === 0 && $enrolments === 0) {
            return;
        }

        $this->failures[] = sprintf(
            'the contract run left %d course row(s) and %d enrolment row(s) behind: '
            . 'transactional() did not roll back',
            $courses,
            $enrolments,
        );

        // The transaction guarantee is already known to be absent, so the
        // rows are removed rather than left on a live site. Only the marked
        // ones: this run created nothing else.
        try {
            $this->database->delete('enrolments', ['last_activity_at' => self::ENROLMENT_MARKER]);

            foreach ($this->database->selectAll(
                Query::from('courses')->select(['id'])->whereContains('title', self::MARKER),
            ) as $row) {
                $this->database->delete('courses', ['id' => (int) $row['id']]);
            }
        } catch (\Throwable $failure) {
            $this->failures[] = 'and the cleanup failed too: ' . $failure->getMessage();
        }
    }

    private function run(): void
    {
        $courses = $this->seed();

        $this->assertPlainAggregates($courses);
        $this->assertConditionalCounts($courses);
        $this->assertGroupedAggregateOverAJoin($courses);
        $this->assertRate($courses);
        $this->assertNullsLast($courses);
        $this->assertOrGroups($courses);
        $this->assertCountOfAGroupedQuery($courses);
        $this->assertAggregatePagingIsStable($courses);
        $this->assertHavingAndDistinctCount($courses);
        $this->assertDuplicateKeyIsMappedToThePortsOwnType($courses);
        $this->assertALockingReadSeesWhatThisTransactionWrote($courses);
    }

    /**
     * Where the adapter declares a locking read, it is a CURRENT read.
     *
     * This is the property the invoice number series depends on. Under
     * REPEATABLE READ a plain SELECT after a failed compare-and-swap
     * re-supplies the same stale value from the transaction's own snapshot for
     * ever, so the counter loop cannot converge; `FOR UPDATE` sees what was
     * actually committed. Asserting it against a value THIS transaction wrote
     * is the strongest form the contract can take without a second connection,
     * and an adapter that answered a stale row here would answer one there.
     *
     * An adapter that declares nothing is not failed: the capability is
     * optional by design and the compare-and-swap stands where it is absent.
     *
     * @param array{busy: int, quiet: int, empty: int} $courses
     */
    private function assertALockingReadSeesWhatThisTransactionWrote(array $courses): void
    {
        if (!$this->database instanceof SupportsLockingReads) {
            return;
        }

        $title = self::MARKER . ' locked ' . $courses['quiet'];
        $this->database->update('courses', ['title' => $title], ['id' => $courses['quiet']]);
        $row = $this->database->selectOneForUpdate(Query::from('courses')->where('id', '=', $courses['quiet']));

        if ($row === null) {
            $this->failures[] = 'selectOneForUpdate() answered nothing for a row that exists';

            return;
        }

        if ((string) $row['title'] !== $title) {
            $this->failures[] = 'selectOneForUpdate() answered a stale row: a locking read must be a current read';
        }
    }

    /**
     * A duplicate key raises the port's own type, and nothing else does.
     *
     * This is the case that makes the idempotency guarantee portable. The
     * grant path turns a unique index into that guarantee by catching exactly
     * one type and re-reading; each host's driver raises something different
     * (Joomla's driver exception, a RuntimeException built from
     * $wpdb->last_error, a PDOException), so an unmapped adapter would make
     * the catch either miss the collision or swallow a connection failure and
     * report a grant that never happened.
     *
     * The second half is asserted too, and it is the half an adapter gets
     * wrong: an over-eager mapping that answered every write failure with
     * this type would be worse than none, because the caller would re-read
     * after a column-length failure and return a row it did not write.
     *
     * @param array{busy: int, quiet: int, empty: int} $courses
     */
    private function assertDuplicateKeyIsMappedToThePortsOwnType(array $courses): void
    {
        $slug = 'contract-dup-' . $courses['busy'];

        $this->database->update('courses', ['slug' => $slug], ['id' => $courses['busy']]);

        $caught = null;

        try {
            $this->database->update('courses', ['slug' => $slug], ['id' => $courses['quiet']]);
        } catch (UniqueConstraintViolation $violation) {
            $caught = $violation;
        } catch (\Throwable $other) {
            $this->failures[] = 'a duplicate slug raised ' . $other::class . ' rather than UniqueConstraintViolation';

            return;
        }

        if ($caught === null) {
            $this->failures[] = 'a duplicate slug was accepted; the unique index on courses.slug is not enforced';

            return;
        }

        $this->same('courses', $caught->table, 'the violation names the table it refused');

        // The other direction: a failure that is not a duplicate key must not
        // wear this type, or the catch that implements idempotency becomes a
        // catch-all with a narrow name.
        try {
            $this->database->insert('courses', ['no_such_column' => 1]);
            $this->failures[] = 'an insert naming a column that does not exist was accepted';
        } catch (UniqueConstraintViolation) {
            $this->failures[] = 'a non-duplicate write failure was mapped to UniqueConstraintViolation';
        } catch (\Throwable) {
            // The expected shape: some driver error, and not the port's own
            // duplicate-key type.
        }
    }

    /**
     * Three courses with a deliberately uneven enrolment shape:
     *
     *   busy:  4 enrolments, 2 active, 1 completed, 1 cancelled
     *   quiet: 2 enrolments, 1 active, 1 completed
     *   empty: none at all
     *
     * so "counts", "counts only what matches" and "has no rate at all" are
     * three different answers and not one answer read three times.
     *
     * @return array{busy: int, quiet: int, empty: int}
     */
    private function seed(): array
    {
        $busy = $this->course('Busy', 'published', null);
        $quiet = $this->course('Quiet', 'published', '2026-01-05 00:00:00');
        $empty = $this->course('Empty', 'draft', '2026-01-03 00:00:00');

        $this->enrolment($busy, 9001, 'active');
        $this->enrolment($busy, 9002, 'active');
        $this->enrolment($busy, 9003, 'completed');
        $this->enrolment($busy, 9004, 'cancelled');
        $this->enrolment($quiet, 9005, 'active');
        $this->enrolment($quiet, 9006, 'completed');

        return ['busy' => $busy, 'quiet' => $quiet, 'empty' => $empty];
    }

    /**
     * @param array{busy: int, quiet: int, empty: int} $courses
     */
    private function assertPlainAggregates(array $courses): void
    {
        $row = $this->database->selectOne(
            Query::from('enrolments')
                ->select(['course_id'])
                ->aggregate(
                    Aggregate::count('rows'),
                    Aggregate::countOf('completed_at', 'with_completion'),
                    Aggregate::min('user_id', 'lowest'),
                    Aggregate::max('user_id', 'highest'),
                    Aggregate::sum('user_id', 'total'),
                    Aggregate::avg('progress_percent', 'mean'),
                )
                ->where('course_id', '=', $courses['busy'])
                ->groupBy('course_id'),
        );

        $this->same(4, (int) ($row['rows'] ?? 0), 'COUNT(*) over a group');
        $this->same(1, (int) ($row['with_completion'] ?? 0), 'COUNT(column) skips nulls');
        $this->same(9001, (int) ($row['lowest'] ?? 0), 'MIN over a group');
        $this->same(9004, (int) ($row['highest'] ?? 0), 'MAX over a group');
        $this->same(36010, (int) ($row['total'] ?? 0), 'SUM over a group');
        $this->same(25, (int) round((float) ($row['mean'] ?? 0)), 'AVG over a group');
    }

    /**
     * @param array{busy: int, quiet: int, empty: int} $courses
     */
    private function assertConditionalCounts(array $courses): void
    {
        $row = $this->database->selectOne(
            Query::from('enrolments')
                ->select(['course_id'])
                ->aggregate(
                    Aggregate::countWhere([Predicate::compare('status', '!=', 'cancelled')], 'enrolled'),
                    Aggregate::countWhere([Predicate::compare('status', '=', 'active')], 'active'),
                    Aggregate::countWhere(
                        [Predicate::compare('status', '=', 'completed'), Predicate::isNotNull('completed_at')],
                        'finished',
                    ),
                )
                ->where('course_id', '=', $courses['busy'])
                ->groupBy('course_id'),
        );

        $this->same(3, (int) ($row['enrolled'] ?? -1), 'a conditional count excludes the rows that do not match');
        $this->same(2, (int) ($row['active'] ?? -1), 'a conditional count on equality');
        $this->same(1, (int) ($row['finished'] ?? -1), 'a conditional count ANDs its predicates');
    }

    /**
     * The hard case: courses ordered by a count of their enrolments, cut to
     * a page at the database. A course with no enrolments must count zero
     * rather than one, which is what separates COUNT(CASE WHEN) from
     * COUNT(*) over a LEFT JOIN.
     *
     * @param array{busy: int, quiet: int, empty: int} $courses
     */
    private function assertGroupedAggregateOverAJoin(array $courses): void
    {
        $rows = $this->database->selectAll(
            $this->coursesByCount()
                ->orderBy('enrolled', Query::SORT_DESC)
                ->orderBy('c.id', Query::SORT_DESC),
        );

        $this->same(3, \count($rows), 'a grouped join answers one row per course');
        $this->same($courses['busy'], (int) ($rows[0]['id'] ?? 0), 'the busiest course sorts first descending');
        $this->same(3, (int) ($rows[0]['enrolled'] ?? -1), 'the count travels beside the row');
        $this->same($courses['empty'], (int) ($rows[2]['id'] ?? 0), 'a course with no enrolments sorts last');
        $this->same(0, (int) ($rows[2]['enrolled'] ?? -1), 'a course with no enrolments counts zero, not one');

        $page = $this->database->selectAll(
            $this->coursesByCount()
                ->orderBy('enrolled', Query::SORT_DESC)
                ->orderBy('c.id', Query::SORT_DESC)
                ->limit(1)
                ->offset(1),
        );

        $this->same(1, \count($page), 'a grouped query pages at the database');
        $this->same($courses['quiet'], (int) ($page[0]['id'] ?? 0), 'the offset lands on the second group');
    }

    /**
     * @param array{busy: int, quiet: int, empty: int} $courses
     */
    private function assertRate(array $courses): void
    {
        $rows = $this->database->selectAll(
            Query::from('courses', 'c')
                ->select(['c.id'])
                ->leftJoin('enrolments', 'e', 'c.id', 'e.course_id')
                ->aggregate(Aggregate::rate(
                    [Predicate::compare('e.status', '!=', 'cancelled')],
                    [Predicate::compare('e.status', '=', 'completed')],
                    'completion_rate',
                ))
                ->whereContains('c.title', self::MARKER)
                ->groupBy('c.id')
                ->orderBy('c.id'),
        );

        $byId = [];

        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row['completion_rate'];
        }

        $this->same(
            33,
            (int) round(100 * (float) ($byId[$courses['busy']] ?? 0)),
            'a rate divides by the rows in its domain, not by every joined row',
        );
        $this->same(
            50,
            (int) round(100 * (float) ($byId[$courses['quiet']] ?? 0)),
            'a rate over a course where half completed',
        );
        $this->true(
            \array_key_exists($courses['empty'], $byId) && $byId[$courses['empty']] === null,
            'a rate with nothing in its domain is null, not zero',
        );
    }

    /**
     * @param array{busy: int, quiet: int, empty: int} $courses
     */
    private function assertNullsLast(array $courses): void
    {
        foreach ([Query::SORT_ASC, Query::SORT_DESC] as $direction) {
            $rows = $this->database->selectAll(
                Query::from('courses')
                    ->select(['id', 'published_at'])
                    ->whereContains('title', self::MARKER)
                    ->orderBy('published_at', $direction, nullsLast: true)
                    ->orderBy('id'),
            );

            $last = $rows[\count($rows) - 1]['id'] ?? 0;
            $this->same(
                $courses['busy'],
                (int) $last,
                sprintf('nulls sort last %s', $direction === Query::SORT_ASC ? 'ascending' : 'descending'),
            );
        }

        $ascending = $this->database->selectAll(
            Query::from('courses')
                ->select(['id'])
                ->whereContains('title', self::MARKER)
                ->orderBy('published_at', Query::SORT_ASC, nullsLast: true)
                ->orderBy('id'),
        );

        $this->same(
            $courses['empty'],
            (int) ($ascending[0]['id'] ?? 0),
            'nulls last still orders the rows that have a value',
        );
    }

    /**
     * @param array{busy: int, quiet: int, empty: int} $courses
     */
    private function assertOrGroups(array $courses): void
    {
        $rows = $this->database->selectAll(
            Query::from('courses')
                ->select(['id'])
                ->whereContains('title', self::MARKER)
                ->whereAnyOf(
                    Predicate::compare('state', '=', 'draft'),
                    Predicate::in('id', [$courses['busy']]),
                )
                ->orderBy('id'),
        );

        $found = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        sort($found);
        $expected = [$courses['busy'], $courses['empty']];
        sort($expected);

        $this->same($expected, $found, 'an OR group matches either branch and ANDs with the rest');

        $empty = $this->database->selectAll(
            Query::from('courses')
                ->select(['id'])
                ->whereContains('title', self::MARKER)
                ->whereAnyOf(Predicate::in('id', []), Predicate::compare('slug', '=', 'no-such-slug'))
                ->orderBy('id'),
        );

        $this->same(0, \count($empty), 'an OR group whose branches all fail matches nothing');
    }

    /**
     * @param array{busy: int, quiet: int, empty: int} $courses
     */
    private function assertCountOfAGroupedQuery(array $courses): void
    {
        $this->same(
            3,
            $this->database->count($this->coursesByCount()),
            'count() of a grouped query counts groups, not rows',
        );

        $this->same(
            2,
            $this->database->count(
                Query::from('enrolments')
                    ->select(['course_id'])
                    ->aggregate(Aggregate::count('rows'))
                    ->whereIn('course_id', [$courses['busy'], $courses['quiet']])
                    ->groupBy('course_id'),
            ),
            'count() of a grouped query ignores the aggregate columns',
        );
    }

    /**
     * Paging an aggregate sort must not repeat or drop a row, which is what
     * a sort with no deterministic tie break does the moment two groups
     * share a count.
     *
     * @param array{busy: int, quiet: int, empty: int} $courses
     */
    private function assertAggregatePagingIsStable(array $courses): void
    {
        $seen = [];

        for ($offset = 0; $offset < 3; $offset++) {
            $page = $this->database->selectAll(
                $this->coursesByCount()
                    ->orderBy('enrolled', Query::SORT_ASC)
                    ->orderBy('c.id', Query::SORT_ASC)
                    ->limit(1)
                    ->offset($offset),
            );

            foreach ($page as $row) {
                $seen[] = (int) $row['id'];
            }
        }

        $unique = array_values(array_unique($seen));

        $this->same(3, \count($seen), 'every page of an aggregate sort returns its row');
        $this->same(3, \count($unique), 'paging an aggregate sort never repeats a row');
        $this->same($courses['empty'], $seen[0] ?? 0, 'the smallest count pages first ascending');
    }

    /**
     * Usage filters need both pieces together: one joined row may appear more
     * than once, and a date predicate belongs after MAX(), not in WHERE.
     * Keeping this in the shared port contract makes the SQLite harness and
     * both live CMS adapters prove the same SQL semantics.
     *
     * @param array{busy: int, quiet: int, empty: int} $courses
     */
    private function assertHavingAndDistinctCount(array $courses): void
    {
        $query = Query::from('enrolments')
            ->select(['course_id'])
            ->aggregate(
                Aggregate::count('enrolment_count'),
                Aggregate::countDistinctOf('status', 'distinct_statuses'),
            )
            ->whereIn('course_id', [$courses['busy'], $courses['quiet']])
            ->groupBy('course_id')
            ->having('enrolment_count', '>', 2);
        $rows = $this->database->selectAll($query);

        $this->same(1, \count($rows), 'HAVING filters after grouping');
        $this->same($courses['busy'], (int) ($rows[0]['course_id'] ?? 0), 'HAVING keeps the matching group');
        $this->same(3, (int) ($rows[0]['distinct_statuses'] ?? 0), 'COUNT DISTINCT removes duplicate values');
        $this->same(1, $this->database->count($query), 'count() retains HAVING while counting matching groups');
    }

    private function coursesByCount(): Query
    {
        return Query::from('courses', 'c')
            ->select(['c.id'])
            ->leftJoin('enrolments', 'e', 'c.id', 'e.course_id')
            ->aggregate(Aggregate::countWhere([Predicate::compare('e.status', '!=', 'cancelled')], 'enrolled'))
            ->whereContains('c.title', self::MARKER)
            ->groupBy('c.id');
    }

    private function course(string $name, string $state, ?string $publishedAt): int
    {
        return $this->database->insert('courses', [
            'title' => self::MARKER . ' ' . $name,
            'slug' => strtolower($name) . '-' . self::MARKER,
            'summary' => null,
            'description' => null,
            'image' => null,
            'category_id' => null,
            'level' => null,
            'language' => null,
            'visibility' => 'private',
            'state' => $state,
            'price_mode' => 'free',
            'completion_rule' => 'all_lessons',
            'completion_threshold' => null,
            'featured' => 0,
            'created_by' => 0,
            'published_at' => $publishedAt,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function enrolment(int $courseId, int $userId, string $status): void
    {
        $this->database->insert('enrolments', [
            'user_id' => $userId,
            'course_id' => $courseId,
            'entitlement_id' => 0,
            'status' => $status,
            'enrolled_at' => self::NOW,
            'completed_at' => $status === 'completed' ? self::NOW : null,
            'last_activity_at' => self::ENROLMENT_MARKER,
            'progress_percent' => $status === 'completed' ? 100 : 0,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function same(mixed $expected, mixed $actual, string $what): void
    {
        if ($expected === $actual) {
            return;
        }

        $this->failures[] = sprintf(
            '%s: expected %s, got %s',
            $what,
            json_encode($expected),
            json_encode($actual),
        );
    }

    private function true(bool $condition, string $what): void
    {
        if (!$condition) {
            $this->failures[] = $what;
        }
    }
}
