<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Reporting;

use Shondalai\VidyaLMS\Core\Application\Assessment\LearnerResultVisibility;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Reporting\KeysetCursor;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;

/**
 * The learner timeline, as a read contract over the table that already holds
 * it.
 *
 * **No new store.** `activity_log` already carries `user_id`, `course_id`,
 * `lesson_id`, `enrolment_id`, `action`, a JSON context, `occurred_at` and a
 * unique `event_ref`, and the projector already writes a row for every event
 * that declares itself projectable, with no per-event branch. What was missing
 * was three things and none of them is a table: keyset pagination on
 * `(occurred_at, id)`, scoping through the same `VisibilityScope` the reports
 * use, and a retention setting, which the access log retention sweep already
 * owns.
 *
 * **Fan-out on read is correct here.** The timeline is queried one subject at a
 * time and there is no follower graph, so the celebrity problem that forces
 * fan-out on write in social feeds does not exist. One indexed range read per
 * request beats a write amplification nobody needs.
 *
 * **Keyset and never offset.** Offset makes the engine build and discard every
 * skipped row and lets rows shift between pages under concurrent inserts. This
 * table is written from fifteen-second heartbeats, so an offset-paged read of
 * it will duplicate and skip rows while it runs, in a way that is invisible in
 * the answer it produces. The cursor lives in
 * {@see \Shondalai\VidyaLMS\Core\Domain\Reporting\KeysetCursor} so no caller
 * invents its own.
 *
 * **The response borrows Activity Streams 2.0 property names** so an integrator
 * recognises the payload: `actor`, `object`, `target`, `published`,
 * `orderedItems`, `totalItems` and `next`. Nothing is stored in AS2 form and no
 * library is needed; it is a vocabulary and not a dependency.
 */
final class GetLearnerTimeline
{
    /** Default page size, and the cap, because a timeline is unbounded by nature. */
    public const DEFAULT_LIMIT = 50;
    public const MAX_LIMIT = 200;

    public function __construct(
        private readonly DatabasePort $database,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * One page of one learner's timeline, newest first.
     *
     * The scope is enforced here rather than in a provider, on the rule the
     * report seam already states: a caller that must filter by scope has to be
     * given the scope and never asked to look it up. A learner reads their own
     * rows through {@see VisibilityScope::forUser()}; anybody reading somebody
     * else's needs reporting permission and is restricted to assigned courses,
     * unless they manage the site.
     *
     * @return array{
     *     totalItems: int,
     *     orderedItems: list<array<string, mixed>>,
     *     next: ?string
     * }
     */
    public function execute(
        Principal $principal,
        int $userId,
        ?VisibilityScope $scope = null,
        ?int $courseId = null,
        ?string $cursor = null,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $this->guard->requireAuthenticated($principal);

        // The subject is derived FIRST, and the capability check is then run
        // against the row set that is actually read. Authorising on $userId and
        // then reading whichever id the scope carried let a caller pass
        // forUser(51) with userId 50 and be handed somebody else's activity
        // with no capability at all, on the read this class's own docblock
        // calls the largest personal-data read in the product.
        $subject = $scope !== null && $scope->type === VisibilityScope::TYPE_USER
            ? ($scope->ids[0] ?? $userId)
            : $userId;

        $courseIds = null;
        if ($principal->userId !== $subject) {
            $this->guard->require($principal, Capability::VIEW_REPORTS);
            if (
                !$this->guard->can($principal, Capability::MANAGE)
                && !$this->guard->can($principal, Capability::ADMIN)
            ) {
                $courseIds = [];
                foreach (
                    $this->database->selectAll(Query::from('course_instructors')
                    ->select(['course_id'])->where('user_id', '=', $principal->userId)) as $assignment
                ) {
                    $id = (int) $assignment['course_id'];
                    if ($this->guard->can($principal, Capability::VIEW_REPORTS, 'course:' . $id)) {
                        $courseIds[] = $id;
                    }
                }
                if ($courseId !== null && !in_array($courseId, $courseIds, true)) {
                    throw new NotAuthorizedException('You may not read this course timeline.');
                }
            }
        }

        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $decoded = KeysetCursor::decode($cursor);
        $page = $this->page($subject, $courseId, $decoded, $limit + 1);
        $count = $this->countQuery($subject, $courseId);
        if ($courseIds !== null) {
            $page->whereIn('al.course_id', $courseIds);
            $count->whereIn('al.course_id', $courseIds);
        }
        $rows = $this->database->selectAll($page);
        $more = \count($rows) > $limit;
        $rows = $more ? \array_slice($rows, 0, $limit) : $rows;

        $items = [];
        $last = null;
        $hiddenResults = [];

        foreach ($rows as $row) {
            $item = $this->asActivityStreamsItem($row);
            $rowCourseId = (int) ($row['course_id'] ?? 0);
            if (
                $principal->userId === $subject
                && ($hiddenResults[$rowCourseId] ??= LearnerResultVisibility::hidesCourseResults(
                    $this->database,
                    $rowCourseId,
                ))
            ) {
                $item['context'] = array_intersect_key($item['context'], array_flip([
                    'attempt_id', 'quiz_id', 'lesson_id', 'enrolment_id', 'user_id', 'course_id',
                ]));
            }
            $items[] = $item;
            $last = $row;
        }

        return [
            'totalItems' => $this->database->count($count),
            'orderedItems' => $items,
            'next' => $more && $last !== null
                ? KeysetCursor::of([(string) $last['occurred_at'], (int) $last['id']])->encode()
                : null,
        ];
    }

    /**
     * The keyset page, descending on the indexed pair.
     *
     * The comparison is the lexicographic one the pair demands: strictly older
     * than the cursor's instant, or the same instant with a smaller id. Comparing
     * on `occurred_at` alone would drop every row that shares the boundary
     * second with the last row of the previous page, which on a table written
     * from fifteen-second heartbeats is not a rare case.
     */
    private function page(int $userId, ?int $courseId, ?KeysetCursor $cursor, int $limit): Query
    {
        $query = Query::from('activity_log', 'al')
            ->select(['al.id', 'al.user_id', 'al.course_id', 'al.lesson_id', 'al.enrolment_id',
                'al.action', 'al.context', 'al.occurred_at', 'al.event_ref'])
            ->where('al.user_id', '=', $userId)
            ->orderBy('al.occurred_at', Query::SORT_DESC)
            ->orderBy('al.id', Query::SORT_DESC)
            ->limit($limit);

        if ($courseId !== null && $courseId > 0) {
            $query->where('al.course_id', '=', $courseId);
        }

        if ($cursor !== null) {
            $at = $cursor->part(0);
            $id = $cursor->part(1);

            if (\is_string($at) && \is_int($id)) {
                // The exact lexicographic comparison on the pair: strictly
                // older, or the same instant with a smaller id. Comparing on
                // occurred_at alone would drop every row sharing the boundary
                // second with the last row of the previous page, which on a
                // table written from fifteen-second heartbeats is common
                // rather than exotic.
                $query->whereAnyOf(
                    Predicate::compare('al.occurred_at', '<', $at),
                    Predicate::allOf(
                        Predicate::compare('al.occurred_at', '=', $at),
                        Predicate::compare('al.id', '<', $id),
                    ),
                );
            }
        }

        return $query;
    }

    private function countQuery(int $userId, ?int $courseId): Query
    {
        $query = Query::from('activity_log', 'al')->where('al.user_id', '=', $userId);

        if ($courseId !== null && $courseId > 0) {
            $query->where('al.course_id', '=', $courseId);
        }

        return $query;
    }

    /**
     * One row in Activity Streams 2.0 property names.
     *
     * The names are borrowed and the shape is ours: an integrator recognises
     * `actor`, `object`, `target` and `published` without reading a schema, and
     * nothing is stored in this form.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function asActivityStreamsItem(array $row): array
    {
        $context = [];

        if (\is_string($row['context'] ?? null) && $row['context'] !== '') {
            $decoded = json_decode((string) $row['context'], true);
            $context = \is_array($decoded) ? $decoded : [];
        }

        return [
            'id' => (int) $row['id'],
            'type' => (string) $row['action'],
            'actor' => ['type' => 'user', 'id' => (int) $row['user_id']],
            'object' => $row['lesson_id'] === null
                ? null
                : ['type' => 'lesson', 'id' => (int) $row['lesson_id']],
            'target' => $row['course_id'] === null
                ? null
                : ['type' => 'course', 'id' => (int) $row['course_id']],
            'published' => (new \DateTimeImmutable(
                (string) $row['occurred_at'],
                new \DateTimeZone('UTC'),
            ))->format(DATE_ATOM),
            'context' => $context,
        ];
    }
}
