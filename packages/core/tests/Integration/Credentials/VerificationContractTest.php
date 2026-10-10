<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateVerification;
use Shondalai\VidyaLMS\Core\Application\Credentials\ReissueCertificate;
use Shondalai\VidyaLMS\Core\Application\Credentials\RevokeCertificate;
use Shondalai\VidyaLMS\Core\Application\Credentials\VerifyCertificate;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Provider\Token\CertificateTokenResolver;

/**
 * The verification contract, including the parts of it that are privacy
 * rules rather than fields.
 */
final class VerificationContractTest extends CredentialsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCourse(7, 'Advanced Weaving');
        $this->ports->users->add(5, 'Asha Learner', 'asha@example.test');
        $this->ports->authorization->grant(42, Capability::MANAGE);
        $this->settings()->set(VerifyCertificate::SETTING_PUBLISH_LEARNER_NAME, true);
    }

    public function testAnUnknownCodeReportsNoStatusRatherThanRevoked(): void
    {
        $result = $this->verify()->execute(Principal::guest(), 'NOTACODE1234');

        self::assertNull($result->statusValue(), 'A mistyped code must not be reported as withdrawn.');
        self::assertFalse($result->valid);
        self::assertFalse($result->revoked);
        self::assertSame('not_found', $result->reason);
    }

    public function testAValidCertificateReportsTheSixValueStatusAndBothBooleans(): void
    {
        $this->seedCourseCredit(7, 360, 'Textiles');
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);

        $result = $this->verify()->execute(Principal::guest(), $certificate->code);

        self::assertSame('active', $result->statusValue());
        self::assertTrue($result->valid);
        self::assertNull($result->reason);
        self::assertFalse($result->revoked);
        self::assertFalse($result->expired);
        self::assertSame('Asha Learner', $result->learnerName);
        self::assertSame('Advanced Weaving', $result->courseTitle);
        self::assertCount(1, $result->credits);
        self::assertSame('6.00', $result->credits[0]->amount);
        self::assertSame('hours', $result->credits[0]->unit);
        self::assertSame(
            'https://example.test/api/v1/credentials/' . $certificate->uuid,
            $result->credentialUrl,
        );
    }

    public function testARevokedCertificateReportsRevokedWithItsDateAndReason(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $this->revoke()->execute(Principal::user(42), $certificate->id, 'issued_in_error');

        $result = $this->verify()->execute(Principal::guest(), $certificate->code);

        self::assertSame('revoked', $result->statusValue());
        self::assertSame(CertificateVerification::REASON_REVOKED, $result->reason);
        self::assertTrue($result->revoked);
        self::assertNotNull($result->revokedAt);
    }

    public function testASupersededCertificateKeepsResolvingAndLinksOnward(): void
    {
        $original = $this->issueWithSnapshot()->execute(10, 5, 7);
        $replacement = $this->reissue()->execute(Principal::user(42), $original->id);

        $result = $this->verify()->execute(Principal::guest(), $original->code);

        // The old code keeps resolving to a page that says so and links
        // onward, because nothing is ever deleted.
        self::assertSame('superseded', $result->statusValue());
        self::assertFalse($result->valid);
        self::assertSame(CertificateVerification::REASON_SUPERSEDED, $result->reason);
        self::assertSame('https://example.test/verify/' . $replacement->code, $result->supersededByUrl);
    }

    public function testASuspendedCertificateReportsSuspendedRatherThanRevoked(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $this->ports->database->update('certificates', ['status' => 'suspended'], ['id' => $certificate->id]);

        $result = $this->verify()->execute(Principal::guest(), $certificate->code);

        // Answering "revoked" to a payment dispute is a permanent and
        // defamatory answer to a reversible hold.
        self::assertSame('suspended', $result->statusValue());
        self::assertFalse($result->revoked);
        self::assertSame(CertificateVerification::REASON_SUSPENDED, $result->reason);
    }

    public function testACertificateAheadOfItsEffectiveDateIsNotYetValid(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $this->ports->database->update(
            'certificates',
            ['valid_from' => '2030-01-01 00:00:00'],
            ['id' => $certificate->id],
        );

        $result = $this->verify()->execute(Principal::guest(), $certificate->code);

        self::assertSame('not_yet_valid', $result->statusValue());
        self::assertSame(CertificateVerification::REASON_NOT_YET_VALID, $result->reason);
        self::assertFalse($result->expired);
    }

    public function testAnUnknownCodeIsACalmNegativeAndDisclosesNothing(): void
    {
        $result = $this->verify()->execute(Principal::guest(), 'NOTHINGHERE1');

        self::assertFalse($result->valid);
        self::assertSame(CertificateVerification::REASON_NOT_FOUND, $result->reason);
        self::assertNull($result->learnerName);
        self::assertNull($result->courseTitle);
        self::assertNull($result->credentialUrl);
    }

    public function testLookupAcceptsTheCodeWithOrWithoutSeparators(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $grouped = (new CertificateTokenResolver())->display($certificate->code);

        self::assertNotSame($certificate->code, $grouped);

        // People retype what they see, and what they see is grouped.
        $result = $this->verify()->execute(Principal::guest(), $grouped);
        self::assertTrue($result->valid);

        $spaced = $this->verify()->execute(Principal::guest(), strtolower(str_replace('-', ' ', $grouped)));
        self::assertTrue($spaced->valid);
    }

    public function testAnUnpublishedCertificateWithholdsTheLearnerName(): void
    {
        $certificate = $this->issueWithSnapshot(false)->execute(10, 5, 7);

        $result = $this->verify()->execute(Principal::guest(), $certificate->code);

        // Whether the name appears at all is a decision recorded on the row
        // at issue, and a site setting flipped afterwards must not
        // retroactively publish a name against a completion issued under the
        // opposite rule.
        self::assertNull($result->learnerName);
        self::assertSame('Advanced Weaving', $result->courseTitle);
        self::assertTrue($result->valid);
    }

    public function testARowIssuedBeforeTheFlagExistedFallsBackToTheSiteSetting(): void
    {
        // No uuid, which is what a row written before the credential columns
        // existed looks like.
        $certificate = $this->writeCertificate(5, 7, 10, 'LEGACYXXXXXX', null, 1, null, false);
        $this->ports->database->update('certificates', ['uuid' => null], ['id' => $certificate->id]);

        $result = $this->verify()->execute(Principal::guest(), 'LEGACYXXXXXX');

        // Reading the defaulted zero instead would silently blank the name on
        // every certificate a site had already issued.
        self::assertSame('Asha Learner', $result->learnerName);
    }

    public function testTheStatusPrecedenceIsPublishedAndOrdered(): void
    {
        $past = new \DateTimeImmutable('2020-01-01 00:00:00', new \DateTimeZone('UTC'));
        $future = new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC'));
        $now = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));

        self::assertSame(
            CredentialStatus::Revoked,
            CredentialStatus::resolve(CredentialStatus::Revoked, null, $past, $now),
        );
        self::assertSame(
            CredentialStatus::Superseded,
            CredentialStatus::resolve(CredentialStatus::Superseded, null, $past, $now),
        );
        self::assertSame(
            CredentialStatus::Suspended,
            CredentialStatus::resolve(CredentialStatus::Suspended, null, $past, $now),
        );
        self::assertSame(
            CredentialStatus::NotYetValid,
            CredentialStatus::resolve(CredentialStatus::Active, $future, $past, $now),
        );
        self::assertSame(
            CredentialStatus::Expired,
            CredentialStatus::resolve(CredentialStatus::Active, null, $past, $now),
        );
        self::assertSame(
            CredentialStatus::Active,
            CredentialStatus::resolve(CredentialStatus::Active, null, $future, $now),
        );

        // Only the four stored values are ever written; the other two are the
        // clock's answer, so a clock change cannot leave a row stale.
        self::assertSame(
            ['active', 'suspended', 'revoked', 'superseded'],
            CredentialStatus::storedValues(),
        );
    }

    private function verify(): VerifyCertificate
    {
        return new VerifyCertificate(
            $this->certificates,
            $this->courseTitles,
            $this->ports->users,
            $this->ports->clock,
            new Settings(new DatabaseSettingsRepository($this->ports->database, $this->ports->clock)),
            $this->certificateCredits(),
            'https://example.test/verify',
            'https://example.test/api/v1/credentials',
        );
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
