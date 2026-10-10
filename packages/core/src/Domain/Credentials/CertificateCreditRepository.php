<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * The credit rows one issuance carries.
 *
 * They are written inside the certificate's own transaction, because a credit
 * row without its certificate is a compliance defect under regimes that
 * retain records for five and seven years, and they are read years later by a
 * query that groups on scheme and reporting date.
 */
interface CertificateCreditRepository
{
    /**
     * @return list<CertificateCredit>
     */
    public function forCertificate(int $certificateId): array;

    public function insert(int $certificateId, CertificateCredit $credit): int;

    /**
     * @param list<CertificateCredit> $credits
     */
    public function insertAll(int $certificateId, array $credits): void;

    /**
     * Every unreported row under one scheme, which is what a periodic batch
     * export reads. Submission is a batch, never a per-event push.
     *
     * @return list<array{certificate_id: int, credit: CertificateCredit}>
     */
    public function unreportedForScheme(string $scheme, int $limit): array;

    public function markReported(int $creditId, \DateTimeImmutable $reportedAt): void;
}
