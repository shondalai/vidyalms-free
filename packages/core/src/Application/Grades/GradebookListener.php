<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Grades;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Domain\Events\SubmissionGraded;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeChange;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeItem;
use Shondalai\VidyaLMS\Core\Domain\Grades\GradeProgress;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * The assignment half of the gradebook fills itself from the grading use case
 * that already exists.
 *
 * It is a listener and not an edit to that use case, which is the whole point
 * of the seam: the submission grader is shipped, it is correct, and it should
 * not have to know that a gradebook exists. A site that never opens the
 * gradebook pays one insert per grade for a table nobody reads, which is the
 * price of the change log being complete.
 *
 * **The quiz half is not here, and the reason is worth stating.** A quiz cell
 * holds the learner's grade for the quiz and not one attempt's score, and only
 * {@see \Shondalai\VidyaLMS\Core\Application\Progress\RecomputeCourseProgress}
 * knows which attempts count, which quizzes the learner can still reach, and
 * how the counted attempts reduce to one number. A listener writing each
 * attempt's own percentage into the same cell would fight that recomputation
 * every time a later attempt scored lower. So quiz cells are written through
 * {@see QuizGradeSync} on the recomputation's own path, and every write on both
 * paths still goes through {@see RecordGrade}, which is what keeps the change
 * log complete.
 *
 * **A listener failure never fails the grading.** The event bus already logs
 * and continues, and the direction that matters is the safe one: a submission
 * that was marked and did not reach the gradebook is repaired by the next
 * regrade, where a grading that failed because a gradebook insert did is a
 * marker's work lost.
 *
 * **The item maximum comes from the event and not from a lookup.** A quiz
 * attempt reports a percentage, so its item is out of a hundred and the cell
 * snapshots a hundred; an assignment reports points awarded out of maximum
 * points, so both travel and the cell snapshots the maximum the marker actually
 * used. Reading the current configuration instead would reinterpret an old
 * grade against a maximum that has since moved, which is the exact defect
 * `raw_grade_max` exists to prevent.
 */
final class GradebookListener
{
    /** The percentage scale a quiz item is graded on. */
    private const PERCENTAGE_MAX = 100.0;

    public function __construct(
        private readonly RecordGrade $grades,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(DomainEvent $event): void
    {
        try {
            if ($event instanceof SubmissionGraded) {
                $this->onSubmissionGraded($event);
            }
        } catch (\Throwable $failure) {
            // Logged rather than rethrown, on the bus's own terms: the grade
            // itself is already committed and a gradebook row is a derived
            // fact, so losing one is a repair job and losing the grade is not.
            $this->logger->warning(
                'A VidyaLMS grade could not be recorded in the gradebook: {event}, {reason}.',
                ['event' => $event->eventName(), 'reason' => $failure->getMessage()],
            );
        }
    }

    private function onSubmissionGraded(SubmissionGraded $event): void
    {
        $max = $event->maxPoints > 0.0 ? $event->maxPoints : self::PERCENTAGE_MAX;
        $item = GradeItem::forSource(
            $event->courseId,
            GradeItem::SOURCE_ASSIGNMENT,
            $event->assignmentId,
            'Assignment ' . $event->assignmentId,
            $max,
        );

        $this->grades->bySystem(
            $item,
            $event->userId,
            round($event->pointsAwarded, 5),
            $max,
            GradeProgress::humanGraded(),
            GradeChange::REASON_REGRADE,
            $event->idempotencyKey(),
            [
                'submission_id' => $event->submissionId,
                'assignment_id' => $event->assignmentId,
                'lesson_id' => $event->lessonId,
                'enrolment_id' => $event->enrolmentId,
            ],
            $event->occurredAt,
        );
    }
}
