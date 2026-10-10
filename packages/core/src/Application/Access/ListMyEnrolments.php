<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * The caller's own enrolments with the course title and slug joined in.
 */
final class ListMyEnrolments
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly EnrolmentRepository $enrolments,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(Principal $principal): array
    {
        $this->guard->requireAuthenticated($principal);

        return $this->enrolments->listForUserWithCourse($principal->userId);
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int} */
    public function page(Principal $principal, int $page = 1, int $perPage = 20, ?int $courseId = null, ?int $organisationId = null): array
    {
        $this->guard->requireAuthenticated($principal);
        if ($page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 100) {
            throw ValidationException::forField('page', 'Use a positive page and between 1 and 100 rows per page.');
        }
        $this->assertCourseId($courseId);
        if ($organisationId !== null && ($organisationId < 0 || $organisationId > 2147483646)) {
            throw ValidationException::forField('organisation_id', 'Use an organization id, or zero for personal learning.');
        }
        return ['items' => $this->enrolments->pageForUserWithCourse($principal->userId, $perPage, ($page - 1) * $perPage, $courseId, $organisationId),
            'total' => $this->enrolments->countForUserWithCourse($principal->userId, $courseId, $organisationId), 'page' => $page, 'per_page' => $perPage];
    }

    /** @return list<array{id: int, name: string}> */
    public function organisations(Principal $principal): array
    {
        $this->guard->requireAuthenticated($principal);
        return $this->enrolments->organisationsForUser($principal->userId);
    }

    /** A direct course lookup must not scan every enrolment or rely on the first list page.
     * @return array<string, mixed>|null
     */
    public function forCourse(Principal $principal, int $courseId): ?array
    {
        $this->guard->requireAuthenticated($principal);
        $this->assertCourseId($courseId);
        return $this->enrolments->pageForUserWithCourse($principal->userId, 1, 0, $courseId)[0] ?? null;
    }

    private function assertCourseId(?int $courseId): void
    {
        if ($courseId !== null && ($courseId < 1 || $courseId > 2147483646)) {
            throw ValidationException::forField('course_id', 'Use a positive course id.');
        }
    }
}
