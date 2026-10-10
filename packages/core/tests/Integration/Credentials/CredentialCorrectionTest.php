<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\ReissueCertificate;
use Shondalai\VidyaLMS\Core\Application\Credentials\RevokeCertificate;
use Shondalai\VidyaLMS\Core\Application\Credentials\SuspendCertificate;
use Shondalai\VidyaLMS\Core\Application\Credentials\VerifyCertificate;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;

/**
 * A correction never loses the stronger fact.
 *
 * Every case here failed before: the supersession write set status to
 * superseded unconditionally, so a revoked certificate corrected afterwards
 * answered "replaced" to a relying party asking about a credential that was
 * withdrawn, and a suspended one had its hold silently lifted. Reissuing a
 * certificate carrying an issuer-supplied earner id could not succeed at all,
 * because that column is unique and the replacement carries it forward.
 */
final class CredentialCorrectionTest extends CredentialsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCourse(7, 'Advanced Weaving');
        $this->ports->users->add(5, 'Asha Learner', 'asha@example.test');
        $this->ports->authorization->grant(99, Capability::MANAGE);
    }

    public function testAWithdrawnCertificateCannotBeReissuedManuallyOrByAnAlreadyQueuedTask(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);

        $this->revoke()->execute($this->manager(), $certificate->id, 'issued in error');
        foreach ([false, true] as $scheduled) {
            try {
                if ($scheduled) {
                    $this->reissue()->forScheduledTask($certificate->id, 2);
                } else {
                    $this->reissue()->execute($this->manager(), $certificate->id);
                }
                self::fail('Revocation must survive both reissue entry points.');
            } catch (ConflictException $refusal) {
                self::assertSame('revoked', $refusal->extensions['credential_status']);
            }
        }

        $previous = $this->certificates->findById($certificate->id);

        self::assertNotNull($previous);
        self::assertSame(CredentialStatus::Revoked, $previous->storedStatus);
        self::assertNull($previous->supersededById);
        self::assertSame(1, $this->ports->database->count(\Shondalai\VidyaLMS\Core\Port\Database\Query::from('certificates')));
        self::assertNotNull($previous->revokedAt);

        $verification = $this->verify()->execute(Principal::guest(), $certificate->code);

        self::assertSame(CredentialStatus::Revoked->value, $verification->statusValue());
        self::assertTrue($verification->revoked);
    }

    public function testAHeldCertificateIsNotReleasedByACorrection(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);

        $this->suspend()->execute($this->manager(), $certificate->id, 'under investigation');

        $this->expectException(ConflictException::class);

        $this->reissue()->execute($this->manager(), $certificate->id);
    }

    public function testACertificateCarryingAnIssuerEarnerIdCanBeReissued(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7, null, 'ext-earner-42');

        self::assertSame('ext-earner-42', $certificate->issuerEarnerId);

        $replacement = $this->reissue()->execute($this->manager(), $certificate->id);

        self::assertNotSame($certificate->id, $replacement->id);
        self::assertSame('ext-earner-42', $replacement->issuerEarnerId);

        $previous = $this->certificates->findById($certificate->id);
        self::assertNotNull($previous);
        self::assertNull($previous->issuerEarnerId, 'The predecessor keeps holding the unique idempotency key.');
        self::assertSame($replacement->id, $previous->supersededById);
    }

    public function testALapsedCertificateReleasesTheEnrolmentKeyAndANewOneIsIssued(): void
    {
        $templateId = $this->templates->insert('Lapsing', 'html', 'Body', true, 'published', 'test.lapsing');
        $this->ports->database->update(
            'certificate_templates',
            ['expiry_mode' => 'days_after_issue', 'expiry_days' => 30],
            ['id' => $templateId],
        );

        $first = $this->issueWithSnapshot()->execute(10, 5, 7);

        self::assertNotNull($first->expiresAt, 'The template names an expiry the issue path must resolve.');

        $this->ports->clock->advance('P60D');

        self::assertNull($this->certificates->findActiveByEnrolmentId(10));

        $second = $this->issueWithSnapshot()->execute(10, 5, 7);

        self::assertNotSame($first->id, $second->id);

        $lapsed = $this->certificates->findById($first->id);
        self::assertNotNull($lapsed);
        self::assertNull($lapsed->activeEnrolmentKey);
        self::assertSame(CredentialStatus::Active, $lapsed->storedStatus, 'Expiry is derived and never stored.');
    }

    private function manager(): Principal
    {
        return Principal::user(99);
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
            $this->resolveExpiry(),
            $this->ports->database,
            $this->certificateJobs(),
            $this->freezeCredential(),
        );
    }

    private function verify(): VerifyCertificate
    {
        return new VerifyCertificate(
            $this->certificates,
            $this->courseTitles,
            $this->ports->users,
            $this->ports->clock,
            $this->settings(),
            $this->certificateCredits(),
            'https://example.test/verify',
            'https://example.test/api/v1/credentials',
        );
    }
}
