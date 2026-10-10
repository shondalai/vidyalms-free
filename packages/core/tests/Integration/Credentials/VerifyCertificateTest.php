<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateVerification;
use Shondalai\VidyaLMS\Core\Application\Credentials\VerifyCertificate;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;

final class VerifyCertificateTest extends CredentialsTestCase
{
    private VerifyCertificate $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCourse(7, 'Advanced Weaving');
        $this->ports->users->add(5, 'Asha Learner');
        $this->settings()->set(VerifyCertificate::SETTING_PUBLISH_LEARNER_NAME, true);

        $this->useCase = new VerifyCertificate(
            $this->certificates,
            $this->courseTitles,
            $this->ports->users,
            $this->ports->clock,
            $this->settings(),
        );
    }

    public function testGuestMayVerifyAValidCode(): void
    {
        $this->insertCertificate('ABC123XYZ456');

        $result = $this->useCase->execute(Principal::guest(), 'ABC123XYZ456');

        self::assertTrue($result->valid);
        self::assertNull($result->reason);
        self::assertSame('Advanced Weaving', $result->courseTitle);
        self::assertSame('Asha Learner', $result->learnerName);
        self::assertSame('2026-01-15 10:00:00', $result->issuedAt?->format('Y-m-d H:i:s'));
        self::assertNull($result->expiresAt);
    }

    public function testVerificationUsesTheNamePrintedOnTheIssuedCertificate(): void
    {
        $certificate = $this->insertCertificate('FROZENNAME');
        $snapshot = \Shondalai\VidyaLMS\Core\Application\Credentials\TemplateSnapshot::freezeContext(
            ['user.full_name' => 'Asha Learner'], 'en-GB', 'UTC', 'https://example.test/verify/FROZENNAME',
        );
        $this->ports->database->update('certificates', ['context_snapshot' => json_encode($snapshot)], ['id' => $certificate->id]);
        $this->ports->users->add(5, 'A different account name');
        self::assertSame('Asha Learner', $this->useCase->execute(Principal::guest(), 'FROZENNAME')->learnerName);
    }

    public function testUnknownCodeReturnsNotFoundWithoutThrowing(): void
    {
        $result = $this->useCase->execute(Principal::guest(), 'DOESNOTEXIST');

        self::assertFalse($result->valid);
        self::assertSame(CertificateVerification::REASON_NOT_FOUND, $result->reason);
        self::assertNull($result->courseTitle);
        self::assertNull($result->learnerName);
        self::assertNull($result->issuedAt);
        self::assertNull($result->expiresAt);
    }

    public function testRevokedCertificateReportsRevokedWithDetails(): void
    {
        $certificate = $this->insertCertificate('ABC123XYZ456');
        $this->certificates->markRevoked($certificate->id, $this->ports->clock->now());

        $result = $this->useCase->execute(Principal::guest(), 'ABC123XYZ456');

        self::assertFalse($result->valid);
        self::assertSame(CertificateVerification::REASON_REVOKED, $result->reason);
        self::assertSame('Advanced Weaving', $result->courseTitle);
        self::assertSame('Asha Learner', $result->learnerName);
        self::assertSame('2026-01-15 10:00:00', $result->issuedAt?->format('Y-m-d H:i:s'));
    }

    public function testExpiredCertificateReportsExpired(): void
    {
        $this->insertCertificate(
            'ABC123XYZ456',
            new \DateTimeImmutable('2026-01-01 00:00:00', new \DateTimeZone('UTC')),
        );

        $result = $this->useCase->execute(Principal::guest(), 'ABC123XYZ456');

        self::assertFalse($result->valid);
        self::assertSame(CertificateVerification::REASON_EXPIRED, $result->reason);
        self::assertSame('2026-01-01 00:00:00', $result->expiresAt?->format('Y-m-d H:i:s'));
    }

    public function testCertificateWithFutureExpiryIsStillValid(): void
    {
        $this->insertCertificate(
            'ABC123XYZ456',
            new \DateTimeImmutable('2027-01-01 00:00:00', new \DateTimeZone('UTC')),
        );

        $result = $this->useCase->execute(Principal::guest(), 'ABC123XYZ456');

        self::assertTrue($result->valid);
        self::assertNull($result->reason);
        self::assertSame('2027-01-01 00:00:00', $result->expiresAt?->format('Y-m-d H:i:s'));
    }

    private function insertCertificate(string $code, ?\DateTimeImmutable $expiresAt = null): Certificate
    {
        return $this->writeCertificate(5, 7, 10, $code, $expiresAt);
    }
}
