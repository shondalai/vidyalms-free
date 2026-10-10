<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityScope;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The one answer to "is this lesson part of the learner's course".
 *
 * A lesson is, when the lesson is published and the module holding it is
 * published too. The two used to be asked separately: the curriculum view
 * hid a lesson whose module was not published while the completion count and
 * the heartbeat looked at the lesson state alone, so a published lesson in an
 * archived module was invisible and still counted, and the course could not
 * be finished by anybody. Everything that decides what a learner has to do
 * asks this object, so the curriculum, the counter and the assessment entry
 * cannot drift apart again.
 *
 * It is a read across the Curriculum tables and writes nothing.
 *
 * Every read method takes an optional trailing availability scope, and null
 * preserves the previous behaviour byte for byte: the structural answer, a
 * published lesson inside a published module, stays exactly where this
 * docblock says it must. With a scope the same answer is narrowed by the
 * restriction engine's denominator rule, and it is narrowed here rather than
 * in a second class for the reason above.
 *
 * All five read methods take it, not the three obvious ones. quizIsReachable()
 * is what the attempt path calls to enforce reachability, and
 * reachableQuizIdsForCourse() is called twice by the completion recompute,
 * once for the course average and once for the final-quiz rule. Scoping the
 * lesson denominator and leaving the quiz denominator availability-blind would
 * put the divergence this class exists to prevent inside the class instead of
 * across two, on the exact code path that decides whether a course completes.
 * The sixth method, moduleAcceptsPublishedLessons(), takes no scope: it
 * answers a structural question about a module's own publication state with no
 * learner in it, so an availability scope would have nothing to filter.
 */
final class LessonAvailability
{
    public function __construct(private readonly DatabasePort $database)
    {
    }

    /**
     * The lesson, when a learner may work on it. Null covers every reason
     * they may not: no such lesson, the wrong course, a draft or archived
     * lesson, and a lesson inside a module that is not published.
     *
     * @return array<string, mixed>|null
     */
    public function forLearner(int $lessonId, ?int $courseId = null, ?AvailabilityScope $scope = null): ?array
    {
        $row = $this->findLesson($lessonId, $courseId, allowDraftCourse: false);

        if ($row === null || $scope === null) {
            return $row;
        }

        return $scope->allows(GateSubject::TYPE_LESSON, $lessonId) ? $row : null;
    }

    /**
     * Structural lookup for the authorised completion importer. Draft
     * courses can hold imported history; trashed courses, unpublished
     * lessons/modules and lessons of another course are still refused.
     * This does not grant learner access or change publication state.
     *
     * @return array<string, mixed>|null
     */
    public function forCompletionImport(int $lessonId, int $courseId): ?array
    {
        return $this->findLesson($lessonId, $courseId, allowDraftCourse: true);
    }

    /** @return array<string, mixed>|null */
    private function findLesson(int $lessonId, ?int $courseId, bool $allowDraftCourse): ?array
    {
        $query = $this->visible()
            ->select(['l.*'])
            ->join('courses', 'c', 'c.id', 'l.course_id')
            ->where('c.state', '!=', 'trashed')
            ->where('l.id', '=', $lessonId);

        if (!$allowDraftCourse) {
            $query->where('c.state', '!=', 'draft');
        }

        if ($courseId !== null) {
            $query->where('l.course_id', '=', $courseId);
        }

        return $this->database->selectOne($query);
    }

    /**
     * How many lessons the course expects a learner to complete. This is the
     * denominator of every percentage and the input to the all_lessons rule.
     */
    public function countForCourse(int $courseId, ?AvailabilityScope $scope = null): int
    {
        if ($scope === null) {
            return $this->database->count($this->visible()->where('l.course_id', '=', $courseId));
        }

        return \count($this->idsForCourse($courseId, $scope));
    }

    /**
     * @return list<int>
     */
    public function idsForCourse(int $courseId, ?AvailabilityScope $scope = null): array
    {
        $rows = $this->database->selectAll(
            $this->visible()->select(['l.id'])->where('l.course_id', '=', $courseId),
        );

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);

        return $scope === null ? $ids : $scope->countingIds(GateSubject::TYPE_LESSON, $ids);
    }

    /**
     * Whether an author may put a published lesson here. An archived or draft
     * module makes its lessons unreachable, so a published lesson inside one
     * is the disagreement this object exists to prevent.
     */
    public function moduleAcceptsPublishedLessons(int $moduleId): bool
    {
        $row = $this->database->selectOne(
            Query::from('modules')
                ->select(['state'])
                ->where('id', '=', $moduleId),
        );

        return $row !== null && (string) $row['state'] === Module::STATE_PUBLISHED;
    }

    /**
     * A quiz is reachable when the lesson holding it is, and a quiz that
     * belongs to no lesson must have an explicit opening date. Creating a
     * course-level quiz alone must not publish unfinished assessment content.
     */
    public function quizIsReachable(int $quizId, ?AvailabilityScope $scope = null): bool
    {
        $quiz = $this->database->selectOne(
            Query::from('quizzes')
                ->select(['lesson_id', 'opens_at'])
                ->where('id', '=', $quizId),
        );

        if ($quiz === null) {
            return false;
        }

        if ($scope !== null && !$scope->allows(GateSubject::TYPE_QUIZ, $quizId)) {
            return false;
        }

        $lessonId = $quiz['lesson_id'] === null ? 0 : (int) $quiz['lesson_id'];

        if ($lessonId < 1) {
            return $quiz['opens_at'] !== null;
        }

        return $this->forLearner($lessonId, null, $scope) !== null;
    }

    /**
     * Ids of the quizzes a learner can still reach in one course, so an
     * average or a final quiz rule stops counting an archived lesson's quiz.
     *
     * @return list<int>
     */
    public function reachableQuizIdsForCourse(int $courseId, bool $finalOnly = false, ?AvailabilityScope $scope = null): array
    {
        // Two shapes rather than one OR: a quiz with no lesson, and a quiz
        // whose lesson is still part of the course. The read port carries no
        // nested condition group, and two indexed reads say it more plainly
        // than one would.
        $detached = Query::from('quizzes')
            ->select(['id'])
            ->where('course_id', '=', $courseId)
            ->whereNull('lesson_id')
            ->whereNotNull('opens_at');

        // The attached shape routes through the same visible() join the
        // lesson denominator uses, so there is one query shape and one
        // answer rather than two that can drift.
        $attached = Query::from('quizzes', 'q')
            ->select(['q.id', 'q.lesson_id'])
            ->join('lessons', 'l', 'q.lesson_id', 'l.id')
            ->join('modules', 'm', 'l.module_id', 'm.id')
            ->where('q.course_id', '=', $courseId)
            ->where('l.state', '=', Lesson::STATE_PUBLISHED)
            ->where('m.state', '=', Module::STATE_PUBLISHED);

        if ($finalOnly) {
            $detached->where('is_final', '=', 1);
            $attached->where('q.is_final', '=', 1);
        }

        $ids = [];
        $lessonOfQuiz = [];

        foreach ($this->database->selectAll($detached) as $row) {
            $ids[] = (int) $row['id'];
        }

        foreach ($this->database->selectAll($attached) as $row) {
            $quizId = (int) $row['id'];
            $ids[] = $quizId;
            $lessonOfQuiz[$quizId] = (int) $row['lesson_id'];
        }

        if ($scope === null || $ids === []) {
            return $ids;
        }

        $countingLessons = array_flip($scope->countingIds(GateSubject::TYPE_LESSON, array_values($lessonOfQuiz)));
        $countingQuizzes = array_flip($scope->countingIds(GateSubject::TYPE_QUIZ, $ids));
        $scoped = [];

        foreach ($ids as $quizId) {
            if (!isset($countingQuizzes[$quizId])) {
                continue;
            }

            $lessonId = $lessonOfQuiz[$quizId] ?? null;

            if ($lessonId !== null && !isset($countingLessons[$lessonId])) {
                continue;
            }

            $scoped[] = $quizId;
        }

        return $scoped;
    }

    private function visible(): Query
    {
        return Query::from('lessons', 'l')
            ->join('modules', 'm', 'l.module_id', 'm.id')
            ->where('l.state', '=', Lesson::STATE_PUBLISHED)
            ->where('m.state', '=', Module::STATE_PUBLISHED);
    }
}
