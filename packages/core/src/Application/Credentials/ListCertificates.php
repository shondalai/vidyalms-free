<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\ListingPolicy;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCriteria;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

/**
 * Every certificate issued on the site, newest first.
 *
 * Filter, sort and page all happen at the database. Status is not a stored
 * column but three predicates over the clock, so an expiry that passed a
 * minute ago shows as expired without any row having been rewritten. The
 * search matches a verification code exactly or a person, and a person lives
 * in the host CMS, so the term goes to the users port first and comes back
 * as ids the query filters on.
 *
 * Requires manage.
 */
final class ListCertificates
{
    public const SORTS = CertificateRepository::SORT_COLUMNS;

    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly CertificateRecords $records,
        private readonly UsersPort $users,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
        private readonly ListingPolicy $policy,
    ) {
    }

    public function execute(
        Principal $principal,
        ?string $status = null,
        ?int $courseId = null,
        ?int $templateId = null,
        ?string $search = null,
        string $sort = 'issued_at',
        string $order = 'desc',
        int $page = 1,
        int $perPage = 20,
    ): CertificateRecordPage {
        $this->guard->require($principal, Capability::MANAGE);

        if ($status !== null && !\in_array($status, CertificateCriteria::allowedStatuses(), true)) {
            throw ValidationException::forField('status', 'Unknown certificate status.');
        }

        if (!\in_array($sort, self::SORTS, true)) {
            throw ValidationException::forField('sort', 'Unknown sort.');
        }

        $direction = strtolower($order);

        if (!\in_array($direction, ['asc', 'desc'], true)) {
            throw ValidationException::forField('order', 'The order must be asc or desc.');
        }

        if ($courseId !== null && $courseId < 1) {
            throw ValidationException::forField('course_id', 'A positive course id is required.');
        }

        if ($templateId !== null && $templateId < 1) {
            throw ValidationException::forField('template_id', 'A positive template id is required.');
        }

        if ($page < 1) {
            throw ValidationException::forField('page', 'Must be 1 or more.');
        }

        if ($perPage < 1 || $perPage > $this->policy->maxPerPage) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField('per_page', sprintf('Must be between 1 and %d.', $this->policy->maxPerPage));
        }

        $now = $this->clock->now();
        $criteria = new CertificateCriteria($now, $courseId, $status, templateId: $templateId);
        $term = $search === null || trim($search) === '' ? null : trim($search);

        if ($term !== null) {
            // A verification code is unambiguous uppercase alphanumeric, so
            // uppercasing the term is what a case-insensitive code match is
            // on a host whose collation is case sensitive and on one whose
            // collation is not.
            $criteria = $criteria->searchingFor(strtoupper($term), $this->userIdsMatching($term));
        }

        $total = $this->certificates->countRecords($criteria);
        $certificates = $this->certificates->pageOf(
            $criteria,
            $sort,
            $direction === 'desc' ? Query::SORT_DESC : Query::SORT_ASC,
            $perPage,
            ($page - 1) * $perPage,
        );

        return new CertificateRecordPage(
            $this->records->forCertificates($certificates, $now),
            $total,
            $page,
            $perPage,
        );
    }

    /**
     * @return list<int>
     */
    private function userIdsMatching(string $term): array
    {
        $ids = [];

        foreach ($this->users->idsMatching($term) as $id) {
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        ksort($ids);

        return array_values($ids);
    }
}
