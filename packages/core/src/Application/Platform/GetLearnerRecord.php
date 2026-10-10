<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Access\EnrolmentListing;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateRecords;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

/**
 * One person's whole history on the site: every enrolment with its state,
 * progress and entitlement, and every certificate, revoked ones included.
 * A user the host CMS does not know is not found; a known user with nothing
 * yet answers an empty record.
 *
 * Requires manage.
 */
final class GetLearnerRecord
{
    public function __construct(
        private readonly EnrolmentRepository $enrolments,
        private readonly EnrolmentListing $listing,
        private readonly CertificateRepository $certificates,
        private readonly CertificateRecords $records,
        private readonly UsersPort $users,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
    ) {
    }

    public function execute(Principal $principal, int $userId): LearnerRecord
    {
        $this->guard->require($principal, Capability::MANAGE);

        $account = $this->users->findById($userId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('User', $userId);

        return new LearnerRecord(
            $account,
            $this->listing->resolve($this->enrolments->recordsForUser($userId)),
            $this->records->forCertificates($this->certificates->findByUserId($userId), $this->clock->now()),
        );
    }
}
