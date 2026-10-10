<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Progress;

use Shondalai\VidyaLMS\Core\Domain\Reporting\KeysetCursor;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;

/**
 * The read contract the learner timeline and its exports need.
 *
 * activity_log already carries the user, course, lesson, enrolment, action, a
 * JSON context, an instant and a unique event reference, and the projector
 * already writes a row for every projectable event with no per-event branch.
 * What was missing was three things, and this is two of them: keyset paging
 * on (occurred_at, id), and scoping through the same VisibilityScope the
 * reports use. The third, a retention setting, is a settings row rather than
 * anything here.
 *
 * **This is a reader and not a store.** No new table, no fan-out on write.
 * Fan-out on read over one table is correct here: a timeline is queried one
 * subject at a time and there is no follower graph, so the celebrity problem
 * that forces fan-out on write in social feeds does not exist.
 *
 * **Why the pair and not the instant.** This is the highest-volume table in
 * the product and it is written from fifteen-second heartbeats. Paging it by
 * offset makes the engine build and discard every skipped row and lets rows
 * shift between pages while the export runs, so an export produces duplicates
 * and gaps and nothing says it did. occurred_at alone is not unique, so it is
 * not a sort key either: a page boundary falling inside a burst of rows
 * sharing one second either repeats them or skips them.
 */
final class SqlActivityLogReader
{
    private const TABLE = 'activity_log';

    public function __construct(
        private readonly DatabasePort $database,
    ) {
    }

    /**
     * One page of the timeline, oldest first, resumed from an opaque cursor.
     *
     * @return array{rows: list<array<string, mixed>>, next: ?KeysetCursor}
     */
    public function page(
        ?KeysetCursor $cursor,
        int $limit,
        ?VisibilityScope $scope = null,
        ?\DateTimeImmutable $from = null,
        ?\DateTimeImmutable $until = null,
    ): array {
        if ($limit < 1) {
            throw new \InvalidArgumentException('A page holds at least one row.');
        }

        $query = Query::from(self::TABLE)->orderBy('occurred_at')->orderBy('id')->limit($limit + 1);

        if ($from !== null) {
            $query = $query->where('occurred_at', '>=', $from->format('Y-m-d H:i:s'));
        }

        if ($until !== null) {
            $query = $query->where('occurred_at', '<', $until->format('Y-m-d H:i:s'));
        }

        $query = $this->narrow($query, $scope);

        if ($cursor !== null) {
            // The lexicographic "after this key" predicate, spelled out
            // because the query grammar has no row-value comparison: either a
            // strictly later instant, or the same instant and a strictly later
            // id. Comparing on the instant alone is what repeats or skips a
            // burst of rows sharing one second.
            $occurredAt = (string) $cursor->part(0);
            $id = (int) $cursor->part(1);

            $query = $query->whereAnyOf(
                Predicate::compare('occurred_at', '>', $occurredAt),
                Predicate::allOf(
                    Predicate::compare('occurred_at', '=', $occurredAt),
                    Predicate::compare('id', '>', $id),
                ),
            );
        }

        $rows = $this->database->selectAll($query);
        $hasMore = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);
        $last = $rows === [] ? null : $rows[\count($rows) - 1];

        return [
            'rows' => $rows,
            'next' => $hasMore && $last !== null
                ? KeysetCursor::of([(string) $last['occurred_at'], (int) $last['id']])
                : null,
        ];
    }

    /**
     * Applies the scope, refusing anything it cannot express.
     *
     * A scope this reader does not understand narrows to nothing rather than
     * to everything, which is the only safe direction: a timeline that
     * answered site-wide because it did not recognise a scope type would hand
     * one customer's rows to another the day a new scope type is added.
     */
    private function narrow(Query $query, ?VisibilityScope $scope): Query
    {
        if ($scope === null || $scope->isSiteWide()) {
            return $query;
        }

        return match ($scope->type) {
            VisibilityScope::TYPE_USER => $query->whereIn('user_id', $scope->ids()),
            VisibilityScope::TYPE_COURSE => $query->whereIn('course_id', $scope->ids()),
            default => $query->where('id', '=', 0),
        };
    }
}
