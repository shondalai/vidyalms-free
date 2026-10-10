<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Progress;

use Shondalai\VidyaLMS\Core\Domain\Reporting\KeysetCursor;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;

interface LessonProgressRepository
{
    public function findByEnrolmentAndLesson(int $enrolmentId, int $lessonId): ?LessonProgress;

    /**
     * @return list<LessonProgress>
     */
    public function listByEnrolment(int $enrolmentId): array;

    /**
     * Completed rows that still point at a published lesson; unpublishing a
     * lesson removes it from both sides of the completion fraction.
     */
    public function countCompletedPublishedLessons(int $enrolmentId): int;

    /**
     * The completed count over an explicit id set.
     *
     * The numerator is read from the same id set as the denominator, so the
     * percentage can never exceed 100 and a course can never become
     * uncompletable by arithmetic. Without it a scoped denominator and an
     * unscoped numerator disagree on the code path that decides whether a
     * course completes: a learner who finished all ten lessons of a course
     * whose tenth later leaves the denominator would compare ten against
     * nine and never complete, or write 111.11 into the percentage.
     *
     * @param list<int> $lessonIds
     */
    public function countCompletedIn(int $enrolmentId, array $lessonIds): int;

    /**
     * Idempotent upsert on the enrolment and lesson pair.
     */
    public function save(LessonProgress $progress): void;

    /**
     * One page of rows in a stable order, resumed from an opaque cursor.
     *
     * The export read contract, and the reason it is not listByEnrolment with
     * an offset. Offset makes the engine build and discard every skipped row
     * and lets rows shift between pages under concurrent writes, and lesson
     * progress is written by every heartbeat, so an offset-paged export of a
     * busy site produces duplicates and gaps and nothing says it did.
     *
     * The sort key is (enrolment_id, id), which is stable, indexed and unique.
     * A null cursor starts at the beginning; a null next cursor in the answer
     * means the last page has been served.
     *
     * **The cursor carries no authority and the scope does.** A cursor is an
     * opaque position and nothing more: a client that composed one would
     * choose which enrolment a page starts at, and with no scope beside it
     * that page is the whole table. The scope is therefore an argument here,
     * narrowed the same way the activity log reader narrows, with a scope kind
     * this reader cannot honour refused rather than ignored. It is trailing
     * and defaulted so the shipped two-argument call keeps working, and the
     * default is site-wide, which is the only honest reading of "no scope
     * given" on a repository interface.
     *
     * @param ?VisibilityScope $scope how far the caller may see
     *
     * @return array{rows: list<LessonProgress>, next: ?KeysetCursor}
     */
    public function pageByKeyset(?KeysetCursor $cursor, int $limit, ?VisibilityScope $scope = null): array;
}
