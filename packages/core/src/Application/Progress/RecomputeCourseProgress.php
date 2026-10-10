<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Progress;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityEngine;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityScope;
use Shondalai\VidyaLMS\Core\Application\Curriculum\LessonAvailability;
use Shondalai\VidyaLMS\Core\Application\Notification\TransactionalOutbox;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Progress\CompletionEngine;
use Shondalai\VidyaLMS\Core\Domain\Progress\CompletionInput;
use Shondalai\VidyaLMS\Core\Domain\Progress\CourseProgress;
use Shondalai\VidyaLMS\Core\Domain\Progress\CourseProgressRepository;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgressRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Rebuilds one enrolment's course_progress snapshot from the current state of
 * lessons, lesson progress and attempts, then feeds the completion engine.
 * Safe to run any number of times: CourseCompleted fires only on the single
 * transition where completed_at moves from null to a timestamp.
 */
final class RecomputeCourseProgress
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';
    private const ENROLMENT_STATUS_ACTIVE = 'active';
    private const ENROLMENT_STATUS_COMPLETED = 'completed';
    private const ATTEMPT_STATUS_IN_PROGRESS = 'in_progress';

    public function __construct(
        private readonly LessonProgressRepository $lessonProgress,
        private readonly CourseProgressRepository $courseProgress,
        private readonly CompletionEngine $engine,
        private readonly AccessGuard $guard,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
        private readonly DatabasePort $database,
        private readonly LessonAvailability $availability,
        /**
         * Optional and defaulted, because a kernel that predates the
         * restriction engine must keep constructing this use case: with no
         * engine the scope is null and every read answers exactly what it
         * answered before.
         */
        private readonly ?AvailabilityEngine $availabilityEngine = null,
        /**
         * Optional for the same reason: absent, the completion is written and
         * dispatched exactly as before and the listener is the only enqueue
         * path.
         */
        private readonly ?TransactionalOutbox $outbox = null,
        /**
         * The gradebook, which is where the cached average now comes from.
         *
         * Optional and defaulted for the same reason as the two above: a
         * kernel built before the gradebook existed keeps constructing this
         * use case, and with none supplied the average is computed exactly as
         * it always was. With one, the best percentage per reachable quiz is
         * written as a grade cell with its own change entry and the average is
         * folded out of those cells by a GradeAggregation provider, so the
         * number on the progress bar and the number in the gradebook cannot
         * disagree.
         */
        private readonly ?\Shondalai\VidyaLMS\Core\Application\Grades\QuizGradeSync $quizGrades = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $course
     * @param array<string, mixed>      $enrolment
     */
    private function availabilityScope(Principal $principal, ?array $course, array $enrolment): ?AvailabilityScope
    {
        return $this->availabilityEngine?->scopeFor($principal, $course, $enrolment);
    }

    public function execute(Principal $principal, int $enrolmentId): void
    {
        $this->guard->requireAuthenticated($principal);

        $enrolment = $this->database->selectOne(
            Query::from('enrolments')->where('id', '=', $enrolmentId),
        );

        if ($enrolment === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Enrolment', $enrolmentId);
        }

        $ownerId = (int) $enrolment['user_id'];

        if ($ownerId !== $principal->userId) {
            $this->guard->require($principal, Capability::MANAGE);
        }

        $courseId = (int) $enrolment['course_id'];

        $course = $this->database->selectOne(
            Query::from('courses')->where('id', '=', $courseId),
        );

        if ($course === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Course', $courseId);
        }

        // One scope, built once, and both sides of the fraction read from the
        // id set it produces. The numerator moves with the denominator: a
        // lesson that leaves the denominator is still a published lesson, so
        // an unscoped count would leave it in the numerator, and a learner
        // who completed all ten lessons of a course whose tenth later drops
        // out would compare ten against nine and never complete.
        $scope = $this->availabilityScope($principal, $course, $enrolment);
        $lessonIds = $this->availability->idsForCourse($courseId, $scope);
        $lessonsTotal = \count($lessonIds);
        $lessonsCompleted = $this->lessonProgress->countCompletedIn($enrolmentId, $lessonIds);
        $quizAverage = $this->quizAverage($enrolmentId, $courseId, $scope, (int) $enrolment['user_id']);
        $finalQuizPassed = $this->finalQuizPassed($enrolmentId, $courseId, $scope);

        $percent = $lessonsTotal > 0 ? round($lessonsCompleted / $lessonsTotal * 100, 2) : 0.0;
        $now = $this->clock->now();

        $this->courseProgress->save(new CourseProgress(
            $enrolmentId,
            $lessonsTotal,
            $lessonsCompleted,
            $percent,
            $quizAverage,
            $finalQuizPassed,
            $now,
        ));

        $decision = $this->engine->decide(new CompletionInput(
            lessonsTotal: $lessonsTotal,
            lessonsCompleted: $lessonsCompleted,
            rule: (string) $course['completion_rule'],
            threshold: $course['completion_threshold'] === null ? null : (int) $course['completion_threshold'],
            finalQuizPassed: $finalQuizPassed,
        ));

        $nowString = $now->format(self::DATETIME_FORMAT);

        $this->refreshUnlockTasks($principal, $courseId, $enrolment, $course);

        $this->database->update('enrolments', [
            'progress_percent' => $percent,
            'last_activity_at' => $nowString,
            'updated_at' => $nowString,
        ], ['id' => $enrolmentId]);

        if (!$decision) {
            return;
        }

        // The completion transition is guarded in the WHERE clause, not by the
        // row read above: two concurrent recomputes both see completed_at null,
        // but only the one whose guarded update reports an affected row may
        // dispatch, keeping CourseCompleted exactly-once per enrolment.
        //
        // The guarded update and the outbox intent commit together, so a
        // completion whose enqueue fails is not a completion. The guard is
        // unchanged: it is still the affected-row count that decides, and it
        // still decides once.
        $this->write(function () use ($enrolmentId, $nowString, $now, $courseId, $ownerId): array {
            $affected = $this->database->update('enrolments', [
                'status' => self::ENROLMENT_STATUS_COMPLETED,
                'completed_at' => $nowString,
                'updated_at' => $nowString,
            ], [
                'id' => $enrolmentId,
                'status' => self::ENROLMENT_STATUS_ACTIVE,
                'completed_at' => null,
            ]);

            return [
                null,
                $affected === 1 ? [new CourseCompleted($now, $enrolmentId, $courseId, $ownerId)] : [],
            ];
        });
    }

    /**
     * @param callable(): array{0: null, 1: list<\Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent>} $operation
     */
    private function write(callable $operation): void
    {
        if ($this->outbox !== null) {
            $this->outbox->commit($operation);

            return;
        }

        [, $events] = $operation();
        $this->events->dispatchAll($events);
    }

    /**
     * The unlock notice is scheduled from a write path and never from a read.
     *
     * An outline render may not perform scheduler writes: a GET that
     * schedules breaks the query budget, and a learner who never opened the
     * course before the instant would never be told at all. The catch-up for
     * a learner nobody recomputed is the sweep.
     *
     * @param array<string, mixed>|null $course
     * @param array<string, mixed>      $enrolment
     */
    private function refreshUnlockTasks(Principal $principal, int $courseId, array $enrolment, ?array $course): void
    {
        $this->availabilityEngine?->refreshUnlockTasks($principal, $courseId, $enrolment, $course);
    }

    /**
     * Average of the best percentage per quiz over the enrolment's graded
     * attempts; null when no attempt has been graded yet.
     *
     * Only quizzes the learner can still reach count. An archived lesson has
     * left the course, so the quiz inside it must stop steering the average
     * too, or archiving the lesson somebody failed would leave the failure in
     * the number forever.
     */
    private function quizAverage(
        int $enrolmentId,
        int $courseId,
        ?AvailabilityScope $scope,
        int $userId = 0,
    ): ?float {
        $reachable = $this->availability->reachableQuizIdsForCourse($courseId, false, $scope);

        if ($reachable === []) {
            return null;
        }

        $rows = $this->database->selectAll(
            Query::from('attempts', 'a')
                ->select(['a.quiz_id', 'a.percentage'])
                ->join('quizzes', 'q', 'a.quiz_id', 'q.id')
                ->where('a.enrolment_id', '=', $enrolmentId)
                ->where('a.status', '!=', self::ATTEMPT_STATUS_IN_PROGRESS)
                ->where('a.status', '!=', 'abandoned')
                ->whereIn('a.quiz_id', $reachable)
                ->whereNotNull('a.percentage'),
        );

        $bestPerQuiz = [];

        foreach ($rows as $row) {
            $quizId = (int) $row['quiz_id'];
            $percentage = (float) $row['percentage'];
            $bestPerQuiz[$quizId] = isset($bestPerQuiz[$quizId])
                ? max($bestPerQuiz[$quizId], $percentage)
                : $percentage;
        }

        if ($bestPerQuiz === []) {
            return null;
        }

        // The gradebook is the store and the aggregation registry is the
        // arithmetic. The inputs are unchanged: best percentage per quiz, over
        // graded attempts, over the quizzes the learner can still reach. What
        // moves is where the number is kept and who folds it, so
        // course_progress.quiz_average stops being the only cached grade in the
        // product and stops being the one sum nobody else can see.
        if ($this->quizGrades !== null && $userId > 0) {
            $fromGradebook = $this->quizGrades->syncAndAverage($courseId, $userId, $bestPerQuiz);

            if ($fromGradebook !== null) {
                return $fromGradebook;
            }
        }

        return round(array_sum($bestPerQuiz) / \count($bestPerQuiz), 2);
    }

    /**
     * Null when the course has no final quiz; otherwise whether any attempt
     * of this enrolment passed one.
     */
    private function finalQuizPassed(int $enrolmentId, int $courseId, ?AvailabilityScope $scope): ?bool
    {
        // A final quiz whose lesson was archived no longer governs the rule:
        // the course does not contain it any more, so requiring a pass on it
        // would make the course uncompletable by everyone who had not sat it.
        $quizIds = $this->availability->reachableQuizIdsForCourse($courseId, true, $scope);

        if ($quizIds === []) {
            return null;
        }

        $passedCount = $this->database->count(
            Query::from('attempts')
                ->where('enrolment_id', '=', $enrolmentId)
                ->whereIn('quiz_id', $quizIds)
                ->where('status', '!=', 'abandoned')
                ->where('passed', '=', 1),
        );

        return $passedCount > 0;
    }
}
