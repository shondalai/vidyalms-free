<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCriteria;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

final class ListMyCertificates
{
    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @return list<Certificate> newest first
     */
    public function execute(Principal $principal): array
    {
        $this->guard->requireAuthenticated($principal);

        return $this->certificates->findByUserId($principal->userId);
    }

    /** @return array{items: list<Certificate>, total: int, page: int, per_page: int} */
    public function page(Principal $principal, \DateTimeImmutable $now, int $page, int $perPage, ?int $courseId = null): array
    {
        $this->guard->requireAuthenticated($principal);
        if ($page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 100) {
            throw ValidationException::forField('page', 'Use a positive page and between 1 and 100 rows per page.');
        }
        if ($courseId !== null && ($courseId < 1 || $courseId > 2147483646)) {
            throw ValidationException::forField('course_id', 'Use a positive course id.');
        }
        $criteria = new CertificateCriteria($now, courseId: $courseId, userIds: [$principal->userId]);
        return ['items' => $this->certificates->pageOf($criteria, 'issued_at', 'DESC', $perPage, ($page - 1) * $perPage),
            'total' => $this->certificates->countRecords($criteria), 'page' => $page, 'per_page' => $perPage];
    }
}
