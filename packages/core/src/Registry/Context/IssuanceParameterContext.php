<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCredit;

/**
 * What one issuance can tell a token resolver about itself before any token
 * is resolved.
 *
 * It exists because the parameter bag was a static method with a closed
 * argument list, so the only inputs a resolver could ever read were the ones
 * that method happened to assemble. Four of the shipped token families read
 * keys nothing wrote, and the flagship compliance element rendered an empty
 * table on every real issuance as a result. A resolver now contributes its
 * own inputs through ContributesIssuanceParameters and reads them back in
 * resolve(), so an eighth family is a registration rather than an edit to a
 * shipped signature.
 *
 * It carries facts and never ports. A resolver that needs to read a table
 * holds its own repository, exactly as an audience resolver does.
 */
final class IssuanceParameterContext
{
    /**
     * @param list<CertificateCredit> $credits
     */
    private function __construct(
        public readonly string $code,
        public readonly ?string $uuid,
        public readonly \DateTimeImmutable $issuedAt,
        public readonly ?\DateTimeImmutable $completedAt,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly ?string $learnerName,
        public readonly ?int $userId,
        public readonly ?int $courseId,
        public readonly ?string $courseTitle,
        public readonly ?int $enrolmentId,
        public readonly ?int $certificateId,
        public readonly array $credits,
        public readonly string $locale,
        public readonly string $timezone,
    ) {
    }

    /**
     * @param list<CertificateCredit> $credits
     */
    public static function create(
        string $code,
        ?string $uuid,
        \DateTimeImmutable $issuedAt,
        ?\DateTimeImmutable $completedAt = null,
        ?\DateTimeImmutable $expiresAt = null,
        ?string $learnerName = null,
        ?int $userId = null,
        ?int $courseId = null,
        ?string $courseTitle = null,
        ?int $enrolmentId = null,
        ?int $certificateId = null,
        array $credits = [],
        string $locale = 'en-GB',
        string $timezone = 'UTC',
    ): self {
        return new self(
            $code,
            $uuid,
            $issuedAt,
            $completedAt,
            $expiresAt,
            $learnerName,
            $userId,
            $courseId,
            $courseTitle,
            $enrolmentId,
            $certificateId,
            $credits,
            $locale,
            $timezone,
        );
    }
}
