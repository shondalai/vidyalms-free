<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class IssueCertificateTest extends CredentialsTestCase
{
    public function testEndedOrganisationAccessCannotIssueFromHistoricalCompletion(): void
    {
        $now = $this->ports->clock->now()->format('Y-m-d H:i:s');
        $this->ports->database->insert('entitlements', [
            'user_id' => 5, 'course_id' => 7, 'source' => 'org', 'source_ref' => 'org9:course7:member2',
            'organisation_id' => 9, 'org_membership_id' => 2, 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        try {
            $this->issueCertificateUseCase()->execute(10, 5, 7);
            self::fail('A historical org completion is not current issuance authority.');
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException) {
            self::assertSame(0, $this->ports->database->count(Query::from('certificates')));
        }
        $this->ports->database->insert('entitlements', [
            'user_id' => 5, 'course_id' => 7, 'source' => 'manual', 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        self::assertSame(7, $this->issueCertificateUseCase()->execute(10, 5, 7)->courseId);
    }

    public function testIssuesCertificateSeedingTheDefaultTemplate(): void
    {
        $certificate = $this->issueCertificateUseCase()->execute(10, 5, 7);

        self::assertSame('CODE1XXXXXXX', $certificate->code);
        self::assertSame(12, \strlen($certificate->code));
        self::assertSame('2026-01-15 10:00:00', $certificate->issuedAt->format('Y-m-d H:i:s'));
        self::assertSame(10, $certificate->enrolmentId);
        self::assertSame(5, $certificate->userId);
        self::assertSame(7, $certificate->courseId);

        $template = self::row($this->templates->findById($certificate->templateId));
        self::assertSame('Default certificate', $template['name']);

        $row = self::row(
            $this->ports->database->selectOne(Query::from('certificates')->where('id', '=', $certificate->id)),
        );
        self::assertSame('2026-01-15 10:00:00', $row['created_at']);
        self::assertSame('2026-01-15 10:00:00', $row['updated_at']);

        $issued = $this->certificateIssuedEvents();
        self::assertCount(1, $issued);
        self::assertSame($certificate->id, $issued[0]->certificateId);
        self::assertSame('CODE1XXXXXXX', $issued[0]->code);
        self::assertSame(10, $issued[0]->enrolmentId);
        self::assertSame(5, $issued[0]->userId);
        self::assertSame(7, $issued[0]->courseId);
    }

    public function testSecondExecutionReturnsExistingRowWithoutSecondEvent(): void
    {
        $useCase = $this->issueCertificateUseCase();

        $first = $useCase->execute(10, 5, 7);
        $second = $useCase->execute(10, 5, 7);

        self::assertSame($first->id, $second->id);
        self::assertSame($first->code, $second->code);
        self::assertSame(1, $this->ports->database->count(Query::from('certificates')));
        self::assertCount(1, $this->certificateIssuedEvents());
    }

    public function testUsesExistingPublishedDefaultTemplate(): void
    {
        $templateId = $this->templates->insert('Custom default', 'html', '{learner_name}', true, 'published');

        $certificate = $this->issueCertificateUseCase()->execute(10, 5, 7);

        self::assertSame($templateId, $certificate->templateId);
        self::assertSame(1, $this->ports->database->count(Query::from('certificate_templates')));
    }

    public function testRetriesWhenVerificationCodeCollides(): void
    {
        $this->writeCertificate(1, 1, 99, 'CODE1XXXXXXX');

        $certificate = $this->issueCertificateUseCase()->execute(10, 5, 7);

        self::assertSame('CODE2XXXXXXX', $certificate->code);

        $issued = $this->certificateIssuedEvents();
        self::assertCount(1, $issued);
        self::assertSame('CODE2XXXXXXX', $issued[0]->code);
    }
}
