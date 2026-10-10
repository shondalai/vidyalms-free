<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Access\LearnerEnrolment;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateRecord;
use Shondalai\VidyaLMS\Core\Port\Users\UserAccount;

/**
 * One person across the whole site: the audit answer to "where is their
 * progress?". A known account with no enrolments is an empty record, not a
 * missing one.
 */
final class LearnerRecord
{
    /**
     * @param list<LearnerEnrolment>  $enrolments
     * @param list<CertificateRecord> $certificates
     */
    public function __construct(
        public readonly UserAccount $user,
        public readonly array $enrolments,
        public readonly array $certificates,
    ) {
    }
}
