<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Progress;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Progress\CourseProgressRepository;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgressRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class GetMyCourseProgress
{
    public function __construct(
        private readonly LessonProgressRepository $lessonProgress,
        private readonly CourseProgressRepository $courseProgress,
        private readonly AccessGuard $guard,
        private readonly DatabasePort $database,
    ) {
    }

    public function execute(Principal $principal, int $enrolmentId): MyCourseProgress
    {
        $this->guard->requireAuthenticated($principal);

        $enrolment = $this->database->selectOne(
            Query::from('enrolments')->where('id', '=', $enrolmentId),
        );

        if ($enrolment === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Enrolment', $enrolmentId);
        }

        if ((int) $enrolment['user_id'] !== $principal->userId) {
            throw new NotAuthorizedException('The enrolment does not belong to the current user.');
        }

        $summary = $this->courseProgress->findByEnrolment($enrolmentId);
        if (
            $summary !== null && \Shondalai\VidyaLMS\Core\Application\Assessment\LearnerResultVisibility::hidesCourseResults(
                $this->database,
                (int) $enrolment['course_id'],
            )
        ) {
            $summary = new \Shondalai\VidyaLMS\Core\Domain\Progress\CourseProgress(
                $summary->enrolmentId,
                $summary->lessonsTotal,
                $summary->lessonsCompleted,
                $summary->percent,
                null,
                null,
                $summary->computedAt,
            );
        }
        return new MyCourseProgress(
            $enrolmentId,
            (int) $enrolment['course_id'],
            (string) $enrolment['status'],
            $summary,
            $this->lessonProgress->listByEnrolment($enrolmentId),
        );
    }
}
