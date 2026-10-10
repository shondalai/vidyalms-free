<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\ReissueCertificate;
use Shondalai\VidyaLMS\Core\Application\Credentials\RevokeCertificate;
use Shondalai\VidyaLMS\Core\Application\Credentials\SuspendCertificate;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Domain\Events\CertificateReissued;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The status enumeration, the revoke ceremony, supersession and the status
 * list slots, which are one lifecycle and are tested as one.
 */
final class CredentialLifecycleTest extends CredentialsTestCase
{
    private Principal $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCourse(7, 'Advanced Weaving');
        $this->ports->users->add(5, 'Asha Learner');
        $this->ports->authorization->grant(42, Capability::MANAGE);
        $this->manager = Principal::user(42);
    }

    public function testRevocationWinsOverExpiry(): void
    {
        // A certificate revoked for plagiarism in 2026 whose expiry passes in
        // 2028 reports revoked, not expired: "lapsed" and "withdrawn" are
        // materially different answers to a relying party, and the precedence
        // is published rather than left to whichever is computed first.
        $certificate = $this->writeCertificate(
            5,
            7,
            10,
            'REVOKEDXXXXX',
            new \DateTimeImmutable('2028-01-01 00:00:00', new \DateTimeZone('UTC')),
        );

        $this->revoke()->execute($this->manager, $certificate->id, 'violation_of_conditions');

        $this->ports->clock->advance('P1000D');
        $revoked = $this->certificates->findById($certificate->id);
        self::assertNotNull($revoked);

        $now = $this->ports->clock->now();
        self::assertSame(CredentialStatus::Revoked, $revoked->statusAt($now));
        // Both axes are reported independently, because a single-valued
        // status cannot carry both at once.
        self::assertTrue($revoked->isRevoked());
        self::assertTrue($revoked->isExpired($now));
        self::assertSame('violation_of_conditions', $revoked->revocationReason);
    }

    public function testRevocationReleasesTheEnrolmentKeySoAReplacementCanBeIssued(): void
    {
        $issued = $this->issueWithSnapshot()->execute(10, 5, 7);
        self::assertSame('10', $issued->activeEnrolmentKey);

        $this->revoke()->execute($this->manager, $issued->id, 'issued_in_error');

        $released = $this->certificates->findById($issued->id);
        self::assertNotNull($released);
        self::assertNull($released->activeEnrolmentKey);
        self::assertNull($this->certificates->findActiveByEnrolmentId(10));

        $replacement = $this->issueWithSnapshot()->execute(10, 5, 7);
        self::assertNotSame($issued->id, $replacement->id);
        self::assertSame(2, $this->ports->database->count(Query::from('certificates')));
    }

    public function testSuspensionIsReversibleAndKeepsTheEnrolmentKey(): void
    {
        $issued = $this->issueWithSnapshot()->execute(10, 5, 7);
        $suspend = $this->suspend();

        $held = $suspend->execute($this->manager, $issued->id, 'payment_dispute');
        self::assertSame(CredentialStatus::Suspended, $held->storedStatus);
        // A hold does not entitle the holder to a second certificate.
        self::assertSame('10', $held->activeEnrolmentKey);

        $restored = $suspend->reinstate($this->manager, $issued->id);
        self::assertSame(CredentialStatus::Active, $restored->storedStatus);
        self::assertNull($restored->revocationReason);
    }

    public function testARevokedCertificateCannotBeSuspended(): void
    {
        $issued = $this->issueWithSnapshot()->execute(10, 5, 7);
        $this->revoke()->execute($this->manager, $issued->id, 'award_reconsidered');

        $this->expectException(\DomainException::class);
        $this->suspend()->execute($this->manager, $issued->id);
    }

    public function testAReissueSupersedesWithoutMutatingAnythingElse(): void
    {
        $this->seedCourseCredit(7, 360, 'Textiles');

        $original = $this->issueWithSnapshot()->execute(
            10,
            5,
            7,
            new \DateTimeImmutable('2026-01-10 08:00:00', new \DateTimeZone('UTC')),
        );

        self::assertCount(1, $this->certificateCredits()->forCertificate($original->id));

        $this->ports->clock->advance('P30D');
        $replacement = $this->reissue()->execute($this->manager, $original->id);

        $superseded = $this->certificates->findById($original->id);
        self::assertNotNull($superseded);

        self::assertSame(CredentialStatus::Superseded, $superseded->storedStatus);
        self::assertSame($replacement->id, $superseded->supersededById);
        self::assertNull($superseded->activeEnrolmentKey);
        // Nothing is mutated beyond the lifecycle fields: the old row keeps
        // its code, its snapshot and its dates, so the trail stays intact.
        self::assertSame($original->code, $superseded->code);
        self::assertSame($original->templateSnapshot, $superseded->templateSnapshot);
        self::assertSame(
            $original->issuedAt->format('Y-m-d H:i:s'),
            $superseded->issuedAt->format('Y-m-d H:i:s'),
        );

        self::assertNotSame($original->code, $replacement->code);
        self::assertSame($original->id, $replacement->supersedesId);
        self::assertSame('10', $replacement->activeEnrolmentKey);

        // The carry-forward set, each item of which has a failure behind it.
        self::assertSame(
            $original->completedAt?->format('Y-m-d H:i:s'),
            $replacement->completedAt?->format('Y-m-d H:i:s'),
        );
        self::assertSame($original->recipientIdentityHash, $replacement->recipientIdentityHash);
        self::assertSame($original->recipientIdentitySalt, $replacement->recipientIdentitySalt);

        $carried = $this->certificateCredits()->forCertificate($replacement->id);
        self::assertCount(1, $carried);
        self::assertSame('6.00', $carried[0]->amount);
        self::assertSame('Textiles', $carried[0]->fieldOfStudy);
        // Reset, because the new certificate has not been reported yet and
        // the old one's report is a fact about the old one.
        self::assertNull($carried[0]->reportedAt);
    }

    public function testTheReissueEventNamesTheRowThatWasReplaced(): void
    {
        $original = $this->issueWithSnapshot()->execute(10, 5, 7);
        $replacement = $this->reissue()->execute($this->manager, $original->id);

        $events = array_values(array_filter(
            $this->ports->eventBridge->published,
            static fn (DomainEvent $event): bool => $event instanceof CertificateReissued,
        ));

        self::assertCount(1, $events);
        // A key built from the new id is different on every run, so a job
        // killed halfway and restarted would mail the first half twice.
        self::assertSame($original->id, $events[0]->supersededCertificateId);
        self::assertSame($replacement->id, $events[0]->certificateId);
        self::assertSame(
            'certificate.reissued:' . $original->id . ':1',
            $events[0]->idempotencyKey(),
        );
    }

    public function testReissuingAnAlreadySupersededRowDoesNotForkTheChain(): void
    {
        $original = $this->issueWithSnapshot()->execute(10, 5, 7);
        $reissue = $this->reissue();
        $reissue->execute($this->manager, $original->id);

        $again = $reissue->execute($this->manager, $original->id);

        self::assertSame($original->id, $again->id);
        self::assertSame(2, $this->ports->database->count(Query::from('certificates')));
    }

    public function testEveryIssuanceAllocatesOneSlotPerPurposeAndNoTwoSubjectsShareABit(): void
    {
        $this->seedEnrolment(10, 5, 7);
        $this->ports->users->add(6, 'Bo Learner');

        $issue = $this->issueWithSnapshot();
        $first = $issue->execute(10, 5, 7);
        $second = $issue->execute(11, 6, 7);

        $status = $this->credentialStatus();

        foreach ([$first, $second] as $certificate) {
            $entries = $status->entriesFor(IssuanceRecord::SUBJECT_CERTIFICATE, $certificate->id);
            self::assertCount(2, $entries);
            self::assertSame(
                CredentialStatusRepository::PURPOSES,
                array_map(static fn ($entry): string => $entry->purpose, $entries),
            );
        }

        $slots = [];

        foreach ($this->ports->database->selectAll(Query::from('credential_status_entries')) as $row) {
            $slots[] = $row['list_id'] . ':' . $row['list_index'];
        }

        self::assertCount(4, $slots);
        self::assertSame($slots, array_values(array_unique($slots)));

        // Sized to the specification's minimum from the first row, never to
        // the number of credentials issued: the minimum exists for herd
        // privacy and a list sized to the real count leaks the issuer's
        // volume.
        $lists = $this->ports->database->selectAll(Query::from('credential_status_lists'));
        self::assertCount(2, $lists);

        foreach ($lists as $list) {
            self::assertSame(131072, (int) $list['size']);
        }
    }

    public function testAllocationIsIdempotentSoAReplayNeverBurnsASecondBit(): void
    {
        $issued = $this->issueWithSnapshot()->execute(10, 5, 7);
        $status = $this->credentialStatus();

        $first = $status->allocate(IssuanceRecord::SUBJECT_CERTIFICATE, $issued->id, 'revocation');
        $again = $status->allocate(IssuanceRecord::SUBJECT_CERTIFICATE, $issued->id, 'revocation');

        self::assertSame($first->index, $again->index);
        self::assertSame(2, $this->ports->database->count(Query::from('credential_status_entries')));
    }

    public function testManyRevocationsInOneWindowLeaveTheFirstDirtyStamp(): void
    {
        $issue = $this->issueWithSnapshot();
        $revoke = $this->revoke();
        $certificates = [];

        for ($i = 0; $i < 3; $i++) {
            $this->ports->users->add(50 + $i, 'Learner ' . $i);
            $certificates[] = $issue->execute(100 + $i, 50 + $i, 7);
        }

        $revoke->execute($this->manager, $certificates[0]->id, 'issued_in_error');
        $firstStamp = $this->dirtyStamps();

        $this->ports->clock->advance('PT2M');
        $revoke->execute($this->manager, $certificates[1]->id, 'issued_in_error');
        $this->ports->clock->advance('PT2M');
        $revoke->execute($this->manager, $certificates[2]->id, 'issued_in_error');

        // dirty_since is what gives the debounce its ceiling. Moving it
        // forward on every revocation is the trap that lets two hundred
        // revocations over an hour publish five minutes after the last one,
        // so every wallet reads all two hundred as valid for the whole hour.
        self::assertSame($firstStamp, $this->dirtyStamps());
    }

    /**
     * @return list<string>
     */
    private function dirtyStamps(): array
    {
        $stamps = [];

        foreach ($this->ports->database->selectAll(Query::from('credential_status_lists')) as $row) {
            $stamps[] = (string) ($row['dirty_since'] ?? '');
        }

        return $stamps;
    }

    private function revoke(): RevokeCertificate
    {
        return new RevokeCertificate(
            $this->certificates,
            $this->guard,
            $this->ports->clock,
            $this->credentialStatus(),
        );
    }

    private function suspend(): SuspendCertificate
    {
        return new SuspendCertificate(
            $this->certificates,
            $this->guard,
            $this->ports->clock,
            $this->credentialStatus(),
        );
    }

    private function reissue(): ReissueCertificate
    {
        return new ReissueCertificate(
            $this->certificates,
            $this->templates,
            $this->certificateCredits(),
            $this->issuanceSnapshot(),
            $this->guard,
            $this->eventBus,
            $this->ports->clock,
            $this->ports->random,
            null,
            $this->credentialStatus(),
        );
    }
}
