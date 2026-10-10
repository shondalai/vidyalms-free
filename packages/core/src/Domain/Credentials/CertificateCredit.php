<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * One continuing-education credit carried by one issuance.
 *
 * A child row and not a JSON column, because one completion can carry credit
 * under two schemes, because compliance reporting groups by scheme and
 * reporting date, and because the retention obligations behind it run to five
 * years for NASBA and seven for IACET, so these rows are queried years after
 * issue. The amount is a decimal string rather than a float because it is
 * reported to a regulator. Contact minutes sit beside the amount because the
 * minutes are the shared fact and the amounts are derived per scheme.
 *
 * What is scheme-specific lives in params, owned and versioned by the scheme
 * provider: without that the claim that one table covers IACET, NASBA and
 * ACCME is false on inspection, because not one of delivery_method,
 * sponsor_id, increment, credit_type or board has a column.
 */
final class CertificateCredit
{
    /**
     * @param array<string, mixed>|null $params
     */
    public function __construct(
        public readonly string $scheme,
        public readonly string $amount,
        public readonly string $unit,
        public readonly ?string $fieldOfStudy = null,
        public readonly ?string $awardingBodyId = null,
        public readonly ?int $contactMinutes = null,
        public readonly ?array $params = null,
        public readonly int $paramsVersion = 1,
        public readonly ?\DateTimeImmutable $reportedAt = null,
        public readonly ?int $id = null,
        public readonly ?int $certificateId = null,
    ) {
    }

    /**
     * The copy a reissue carries forward: the same facts with the reporting
     * date reset, because the new certificate has not been reported yet and
     * the old one's report is a fact about the old one.
     *
     * Left uncopied entirely, a template correction across a CPE cohort
     * leaves the credit rows on superseded certificates and the compliance
     * export, which groups by scheme and reporting date over active rows,
     * reports zero credits for a whole cohort inside the sixty-day window.
     */
    public function carriedForward(): self
    {
        return new self(
            $this->scheme,
            $this->amount,
            $this->unit,
            $this->fieldOfStudy,
            $this->awardingBodyId,
            $this->contactMinutes,
            $this->params,
            $this->paramsVersion,
            null,
        );
    }
}
