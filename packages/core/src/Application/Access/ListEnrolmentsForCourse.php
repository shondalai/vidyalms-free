<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;

/**
 * A paged roster of a course's enrolments for reporting staff.
 */
final class ListEnrolmentsForCourse
{
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        private readonly CourseAccess $access,
        private readonly EnrolmentRepository $enrolments,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function execute(Principal $principal, int $courseId, int $limit = self::DEFAULT_LIMIT, int $offset = 0): array
    {
        $this->access->require($principal, $courseId, Capability::VIEW_REPORTS);

        $limit = $limit < 1 ? self::DEFAULT_LIMIT : min($limit, self::MAX_LIMIT);
        $offset = max(0, $offset);

        return [
            'items' => $this->enrolments->listForCourse($courseId, $limit, $offset),
            'total' => $this->enrolments->countForCourse($courseId),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
