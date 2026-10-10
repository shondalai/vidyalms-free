<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\RenderCertificate;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Provider\Certificate\HtmlCertificateRenderer;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererRegistry;

final class RenderCertificateTest extends CredentialsTestCase
{
    private RenderCertificate $useCase;

    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCourse(7, 'Advanced Weaving');
        $this->ports->users->add(5, "Mallory <script>alert('x')</script>");

        $registry = new CertificateRendererRegistry();
        $registry->register(new HtmlCertificateRenderer($this->ports->translator));

        $this->certificate = $this->issueCertificateUseCase()->execute(10, 5, 7);

        $this->useCase = new RenderCertificate(
            $this->certificates,
            $this->templates,
            $registry,
            $this->courseTitles,
            $this->ports->users,
            $this->guard,
            $this->ports->translator,
        );
    }

    public function testOwnerRendersWithEscapedLearnerName(): void
    {
        $html = $this->useCase->execute(Principal::user(5), $this->certificate->id, 'https://example.test/verify/');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('Advanced Weaving', $html);
        // The completion date is formatted for a reader now, not as the
        // machine format a database stores: Y-m-d on a human artefact was
        // the defect this replaced.
        self::assertStringContainsString('15 January 2026', $html);
        self::assertStringNotContainsString('2026-01-15', $html);
        self::assertStringContainsString('CODE1XXXXXXX', $html);
        self::assertStringContainsString('https://example.test/verify/CODE1XXXXXXX', $html);
    }

    public function testManagerMayRenderSomeoneElsesCertificate(): void
    {
        $this->ports->authorization->grant(42, Capability::MANAGE);

        $html = $this->useCase->execute(Principal::user(42), $this->certificate->id, 'https://example.test/verify');

        self::assertStringContainsString('Advanced Weaving', $html);
    }

    public function testStrangerIsDenied(): void
    {
        $this->expectException(NotAuthorizedException::class);

        $this->useCase->execute(Principal::user(99), $this->certificate->id, 'https://example.test/verify');
    }

    public function testGuestIsDenied(): void
    {
        $this->expectException(NotAuthorizedException::class);

        $this->useCase->execute(Principal::guest(), $this->certificate->id, 'https://example.test/verify');
    }

    public function testUnknownCertificateIsReported(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->useCase->execute(Principal::user(5), 999, 'https://example.test/verify');
    }
}
