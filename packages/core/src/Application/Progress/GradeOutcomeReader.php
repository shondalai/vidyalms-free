<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Progress;

use Shondalai\VidyaLMS\Core\Application\Shared\GradeOutcome;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Assignments\AssignmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Assignments\Submission;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgressRepository;

/**
 * Reads back what a grade did, once the listeners that complete lessons and
 * recompute course progress have run. It reports state, it does not derive
 * it: the completion engine already decided, and asking it again here would
 * be a second opinion nobody asked for.
 *
 * Holds no authorization; every caller is a use case or handler acting on a
 * write it already authorized.
 */
final class GradeOutcomeReader
{
    public function __construct(
        private readonly LessonProgressRepository $lessonProgress,
        private readonly EnrolmentRepository $enrolments,
        private readonly AssignmentRepository $assignments,
    ) {
    }

    public function forSubmission(Submission $submission): GradeOutcome
    {
        $assignment = $this->assignments->ofId($submission->assignmentId);

        return $this->of(
            $submission->enrolmentId,
            $assignment === null ? null : $assignment->lessonId,
        );
    }

    public function of(int $enrolmentId, ?int $lessonId): GradeOutcome
    {
        $lessonCompleted = false;

        if ($lessonId !== null) {
            $progress = $this->lessonProgress->findByEnrolmentAndLesson($enrolmentId, $lessonId);
            $lessonCompleted = $progress !== null && $progress->isCompleted();
        }

        $enrolment = $this->enrolments->find($enrolmentId);

        return new GradeOutcome(
            $lessonCompleted,
            $enrolment !== null && $enrolment->status === Enrolment::STATUS_COMPLETED,
        );
    }
}
