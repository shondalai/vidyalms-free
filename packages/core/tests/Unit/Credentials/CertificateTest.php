<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Credentials;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;

final class CertificateTest extends TestCase
{
    public function testIsNeitherRevokedNorExpiredByDefault(): void
    {
        $certificate = $this->certificate();

        self::assertFalse($certificate->isRevoked());
        self::assertFalse($certificate->isExpired(new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC'))));
    }

    public function testIsRevokedWhenRevokedAtIsSet(): void
    {
        $certificate = $this->certificate(revokedAt: new \DateTimeImmutable('2026-02-01 00:00:00', new \DateTimeZone('UTC')));

        self::assertTrue($certificate->isRevoked());
    }

    public function testIsExpiredOnlyOnceNowReachesExpiry(): void
    {
        $expiresAt = new \DateTimeImmutable('2026-06-01 00:00:00', new \DateTimeZone('UTC'));
        $certificate = $this->certificate(expiresAt: $expiresAt);

        self::assertFalse($certificate->isExpired($expiresAt->modify('-1 second')));
        self::assertTrue($certificate->isExpired($expiresAt));
        self::assertTrue($certificate->isExpired($expiresAt->modify('+1 day')));
    }

    public function testBelongsToMatchesOnlyTheOwner(): void
    {
        $certificate = $this->certificate();

        self::assertTrue($certificate->belongsTo(5));
        self::assertFalse($certificate->belongsTo(6));
    }

    private function certificate(
        ?\DateTimeImmutable $expiresAt = null,
        ?\DateTimeImmutable $revokedAt = null,
    ): Certificate {
        return new Certificate(
            1,
            5,
            7,
            10,
            2,
            'ABC123XYZ456',
            new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC')),
            $expiresAt,
            null,
            $revokedAt,
        );
    }
}
