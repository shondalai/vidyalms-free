<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentCriteria;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * The Learners tab of a course: every enrolment with the learner, their
 * progress and the entitlement it rides on, so a suspended learner shows both
 * the progress they kept and the reason they were suspended.
 *
 * Suspended rows are included by default; the status filter narrows.
 * Requires reports on the course.
 */
final class ListCourseLearners
{
    public const SORTS = [
        EnrolmentListing::SORT_NAME,
        'progress',
        'last_active',
        'status',
        'enrolled_at',
    ];

    public function __construct(
        private readonly EnrolmentListing $listing,
        private readonly CourseAccess $access,
    ) {
    }

    public function execute(
        Principal $principal,
        int $courseId,
        ?string $status = null,
        ?string $search = null,
        string $sort = 'name',
        string $order = 'asc',
        int $page = 1,
        int $perPage = 20,
    ): LearnerEnrolmentPage {
        $this->access->require($principal, $courseId, Capability::VIEW_REPORTS);

        if ($status !== null && !\in_array($status, Enrolment::STATUSES, true)) {
            throw ValidationException::forField('status', 'Unknown enrolment status.');
        }

        return $this->listing->page(
            new EnrolmentCriteria(courseId: $courseId, status: $status),
            $search,
            $sort,
            $order,
            $page,
            $perPage,
            self::SORTS,
        );
    }
}
