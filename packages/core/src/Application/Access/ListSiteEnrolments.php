<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentCriteria;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * Every enrolment on the site: learner, course, entitlement source and
 * expiry. Site-wide rather than course-scoped, so it requires manage; the
 * per-course rule has nothing to narrow here.
 */
final class ListSiteEnrolments
{
    public const SORTS = ['expires_at', 'enrolled_at', 'last_active', 'progress', 'status'];

    public const DEFAULT_EXPIRING_DAYS = 14;

    public function __construct(
        private readonly EnrolmentRepository $enrolments,
        private readonly EnrolmentListing $listing,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
    ) {
    }

    public function execute(
        Principal $principal,
        ?string $status = null,
        ?int $expiringWithinDays = null,
        ?int $courseId = null,
        ?string $search = null,
        string $sort = 'expires_at',
        string $order = 'asc',
        int $page = 1,
        int $perPage = 20,
    ): SiteEnrolmentPage {
        $this->guard->require($principal, Capability::MANAGE);

        if ($status !== null && !\in_array($status, Enrolment::STATUSES, true)) {
            throw ValidationException::forField('status', 'Unknown enrolment status.');
        }

        if ($expiringWithinDays !== null && $expiringWithinDays < 1) {
            throw ValidationException::forField('expiring_within_days', 'Must be 1 or more.');
        }

        if ($courseId !== null && $courseId < 1) {
            throw ValidationException::forField('course_id', 'A positive course id is required.');
        }

        $now = $this->clock->now();
        $window = $expiringWithinDays ?? self::DEFAULT_EXPIRING_DAYS;

        $criteria = new EnrolmentCriteria(
            courseId: $courseId,
            status: $status,
            expiringAfter: $expiringWithinDays === null ? null : $now,
            expiringBefore: $expiringWithinDays === null ? null : $this->windowEnd($now, $expiringWithinDays),
        );

        $page = $this->listing->page(
            $criteria,
            $search,
            $sort,
            $order,
            $page,
            $perPage,
            self::SORTS,
        );

        $facets = $this->enrolments->facets($now, $this->windowEnd($now, $window));

        return new SiteEnrolmentPage(
            $page,
            $facets['active'],
            $facets['expiring'],
            $facets['suspended'],
            $window,
        );
    }

    private function windowEnd(\DateTimeImmutable $now, int $days): \DateTimeImmutable
    {
        return $now->add(new \DateInterval(sprintf('P%dD', $days)));
    }
}
