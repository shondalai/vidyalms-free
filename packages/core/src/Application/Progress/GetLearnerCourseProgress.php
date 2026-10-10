<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Progress;

use Shondalai\VidyaLMS\Core\Application\Access\EnrolmentListing;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Progress\CourseProgressRepository;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgressRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

/**
 * One learner's progress in one course, the staff view of what the learner
 * sees at /me/progress. A user with no enrolment in the course is not found,
 * not empty: there is nothing to report on.
 *
 * Requires reports on the course.
 */
final class GetLearnerCourseProgress
{
    public function __construct(
        private readonly EnrolmentRepository $enrolments,
        private readonly EnrolmentListing $listing,
        private readonly LessonProgressRepository $lessonProgress,
        private readonly CourseProgressRepository $courseProgress,
        private readonly CourseAccess $access,
    ) {
    }

    public function execute(Principal $principal, int $courseId, int $userId): LearnerCourseProgress
    {
        $this->access->require($principal, $courseId, Capability::VIEW_REPORTS);

        $record = $this->enrolments->recordFor($courseId, $userId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Enrolment', $userId);

        $enrolmentId = $record->enrolment->id();
        $rows = $this->listing->resolve([$record]);

        return new LearnerCourseProgress(
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $rows[0] ?? throw EntityNotFoundException::for('Enrolment', $userId),
            $this->courseProgress->findByEnrolment($enrolmentId),
            $this->lessonProgress->listByEnrolment($enrolmentId),
        );
    }
}
