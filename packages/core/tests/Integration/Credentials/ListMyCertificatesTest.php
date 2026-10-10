<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\ListMyCertificates;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;

final class ListMyCertificatesTest extends CredentialsTestCase
{
    private ListMyCertificates $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useCase = new ListMyCertificates($this->certificates, $this->guard);
    }

    public function testOwnerSeesOnlyTheirOwnCertificatesNewestFirst(): void
    {
        $this->writeCertificate(5, 7, 10, 'AAAAAAAAAAAA');
        $this->ports->clock->advance('P1D');
        $this->writeCertificate(5, 8, 11, 'BBBBBBBBBBBB');
        $this->writeCertificate(6, 7, 12, 'CCCCCCCCCCCC');

        $mine = $this->useCase->execute(Principal::user(5));

        self::assertCount(2, $mine);
        self::assertSame('BBBBBBBBBBBB', $mine[0]->code);
        self::assertSame('AAAAAAAAAAAA', $mine[1]->code);

        foreach ($mine as $certificate) {
            self::assertSame(5, $certificate->userId);
        }
    }

    public function testUserWithoutCertificatesGetsAnEmptyList(): void
    {
        self::assertSame([], $this->useCase->execute(Principal::user(5)));
    }

    public function testGuestIsDenied(): void
    {
        $this->expectException(NotAuthorizedException::class);

        $this->useCase->execute(Principal::guest());
    }
}
