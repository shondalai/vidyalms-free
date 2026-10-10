<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\RevokeCertificate;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class RevokeCertificateTest extends CredentialsTestCase
{
    private RevokeCertificate $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ports->authorization->grant(42, Capability::MANAGE);
        $this->useCase = new RevokeCertificate($this->certificates, $this->guard, $this->ports->clock);
    }

    public function testManagerRevokesACertificate(): void
    {
        $certificate = $this->insertCertificate();

        $revoked = $this->useCase->execute(Principal::user(42), $certificate->id);

        self::assertTrue($revoked->isRevoked());
        self::assertSame('2026-01-15 10:00:00', $revoked->revokedAt?->format('Y-m-d H:i:s'));

        $row = self::row(
            $this->ports->database->selectOne(Query::from('certificates')->where('id', '=', $certificate->id)),
        );
        self::assertSame('2026-01-15 10:00:00', $row['revoked_at']);
    }

    public function testRevokingTwiceKeepsTheOriginalRevocationTime(): void
    {
        $certificate = $this->insertCertificate();

        $this->useCase->execute(Principal::user(42), $certificate->id);
        $this->ports->clock->advance('P1D');
        $again = $this->useCase->execute(Principal::user(42), $certificate->id);

        self::assertSame('2026-01-15 10:00:00', $again->revokedAt?->format('Y-m-d H:i:s'));
    }

    public function testUserWithoutManageCapabilityIsDenied(): void
    {
        $certificate = $this->insertCertificate();

        $this->expectException(NotAuthorizedException::class);

        $this->useCase->execute(Principal::user(99), $certificate->id);
    }

    public function testGuestIsDenied(): void
    {
        $certificate = $this->insertCertificate();

        $this->expectException(NotAuthorizedException::class);

        $this->useCase->execute(Principal::guest(), $certificate->id);
    }

    public function testUnknownCertificateIsReportedToTheManager(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->useCase->execute(Principal::user(42), 12345);
    }

    private function insertCertificate(): Certificate
    {
        return $this->writeCertificate(5, 7, 10, 'ABC123XYZ456');
    }
}
