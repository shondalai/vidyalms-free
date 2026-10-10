<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateJobs;
use Shondalai\VidyaLMS\Core\Application\Credentials\CredentialAssembly;
use Shondalai\VidyaLMS\Core\Application\Credentials\GetCredential;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Provider\Credential\VidyaLmsJsonFormat;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\RenderCertificateTask;

/**
 * The credential is frozen at issue, and the read path serves what was
 * frozen.
 *
 * Every assertion here failed before the freeze had a caller:
 * credential_json, credential_format and proof_type were written by nothing,
 * so the document a wallet dereferences was rebuilt from live rows on every
 * request and followed a course rename, an issuer edit or a package upgrade.
 */
final class FrozenCredentialTest extends CredentialsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCourse(7, 'Advanced Weaving');
        $this->ports->users->add(5, 'Asha Learner', 'asha@example.test');
    }

    public function testIssueFreezesTheCredentialOntoTheRow(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);

        $stored = $this->certificates->findById($certificate->id);

        self::assertNotNull($stored);
        self::assertNotNull($stored->credentialJson, 'The credential was not frozen at issue.');
        self::assertSame(VidyaLmsJsonFormat::KEY, $stored->credentialFormat);
        self::assertNull($stored->proofType, 'The free format carries no securing mechanism.');
    }

    public function testTheCredentialIsByteIdenticalAfterTheCourseIsRenamed(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $credentials = new GetCredential($this->certificates, $this->credentialFormats(), $this->credentialAssembly());

        $before = $credentials->execute((string) $certificate->uuid, ['application/ld+json']);

        $this->ports->database->update('courses', ['title' => 'Renamed Entirely'], ['id' => 7]);

        $after = $credentials->execute((string) $certificate->uuid, ['application/ld+json']);

        self::assertNotNull($before);
        self::assertNotNull($after);
        self::assertSame($before->bytes(), $after->bytes());
        self::assertStringContainsString('Advanced Weaving', $after->bytes());
    }

    public function testTheReadPathReportsThatItServedTheFrozenBytes(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $credentials = new GetCredential($this->certificates, $this->credentialFormats(), $this->credentialAssembly());

        $stored = $this->certificates->findById($certificate->id);
        self::assertNotNull($stored);
        self::assertTrue($credentials->servesFrozenCredential($stored));

        $unfrozen = $this->writeCertificate(6, 7, 11, 'UNFROZENCODE');
        self::assertFalse($credentials->servesFrozenCredential($unfrozen));
    }

    public function testTheIssuerIsFrozenAndSurvivesASettingsEdit(): void
    {
        $this->settings()->set(CredentialAssembly::SETTING_ISSUER, [
            'name' => 'Northlight',
            'url' => 'https://northlight.example',
        ]);

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);

        $this->settings()->set(CredentialAssembly::SETTING_ISSUER, [
            'name' => 'Something Else Entirely',
            'url' => 'https://elsewhere.example',
        ]);

        $context = $this->credentialAssembly()->contextFor(
            $this->certificates->findById($certificate->id) ?? self::fail('no row'),
        );

        self::assertSame('Northlight', $context->issuerName());
        self::assertSame('https://northlight.example', $context->issuerUrl());
    }

    public function testIssueQueuesExactlyOneRenderTaskNamingTheCertificate(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);

        $renders = array_values(array_filter(
            $this->ports->scheduler->tasks,
            static fn (ScheduledTask $task): bool => $task->type === RenderCertificateTask::KEY,
        ));

        self::assertCount(1, $renders);
        self::assertSame($certificate->id, $renders[0]->payload['certificate_id'] ?? null);
        self::assertSame(CertificateJobs::renderDedupeKey($certificate->id), $renders[0]->dedupeKey);
    }

    public function testTheFrozenTokenBagNeverCarriesAPlaintextAddress(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);

        $stored = $this->certificates->findById($certificate->id);
        self::assertNotNull($stored);

        $encoded = json_encode($stored->contextSnapshot, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString('asha@example.test', $encoded);
        self::assertStringNotContainsString('"user.email"', $encoded);
    }

    public function testAFamilyKeepsItsOwnIdentifierOutOfTheFreezeWithNoCoreEdit(): void
    {
        // A closed core constant meant a package registering a resolver that
        // can answer a plaintext identifier had to edit core to keep it out
        // of a snapshot retained for the life of the credential.
        $this->tokenResolvers()->register(new FakeIdentifierResolver());

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $stored = $this->certificates->findById($certificate->id) ?? self::fail('no row');
        $encoded = json_encode($stored->contextSnapshot, JSON_THROW_ON_ERROR);

        self::assertStringContainsString('Northlight Board', $encoded);
        self::assertStringNotContainsString('MB-99001', $encoded);
        self::assertStringNotContainsString('board.member_id', $encoded);
    }

    public function testTheAchievementDescriptionIsNeverEmpty(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $stored = $this->certificates->findById($certificate->id) ?? self::fail('no row');

        $assembly = $this->credentialAssembly();
        $body = (new VidyaLmsJsonFormat($this->ports->translator))->body(
            $assembly->recordFor($stored),
            $assembly->contextFor($stored),
        );

        $subject = $body['credentialSubject'];
        self::assertIsArray($subject);
        $achievement = $subject['achievement'];
        self::assertIsArray($achievement);
        self::assertNotSame('', $achievement['description']);
    }

    public function testAnElementDeclaringACredentialPropertyReachesTheDocument(): void
    {
        $this->seedCourseSummary(7, 'A course about weaving.');
        $this->seedCourseCredit(7, 360);
        $templateId = $this->seedElementsTemplate([
            new CertificateTemplateElement(0, 0, 'credit_statement', [
                'template' => '{credit.amount} {credit.unit}',
                'show_field_of_study' => false,
            ]),
        ]);
        self::assertGreaterThan(0, $templateId);

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $stored = $this->certificates->findById($certificate->id) ?? self::fail('no row');

        $assembly = $this->credentialAssembly();
        $claims = $assembly->contextFor($stored)->elementClaims;

        // AchievementSubject.creditsEarned is credentialSubject, and it is a
        // number. Inside credentialSubject.achievement it is a term Open
        // Badges 3.0 does not define, and as a human sentence it is a value a
        // conforming displayer cannot read.
        self::assertArrayHasKey('credentialSubject.creditsEarned', $claims);
        self::assertArrayNotHasKey('credentialSubject.achievement.creditsEarned', $claims);
        self::assertSame(6.0, $claims['credentialSubject.creditsEarned']);
    }

    public function testABinaryFormatIsNeverFrozenIntoTheTextColumn(): void
    {
        // A site that configured the PAdES key and not the Ed25519 one has
        // application/pdf as its highest-ranked available format. The column
        // is MEDIUMTEXT in a utf8mb4 table, so MySQL in strict mode refuses
        // the bytes; the write was outside the try and ran inside the
        // issuance transaction, so the certificate rolled back and every
        // retry and every backfill sweep took the same path for ever.
        $this->credentialFormats()->register(new FakeBinaryFormat());

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $stored = $this->certificates->findById($certificate->id);

        self::assertNotNull($stored, 'the issuance was lost to a format it could not store');
        self::assertSame(VidyaLmsJsonFormat::KEY, $stored->credentialFormat);
        self::assertNotNull($stored->credentialJson);
        self::assertTrue(
            mb_check_encoding($stored->credentialJson, 'UTF-8'),
            'raw binary reached a utf8mb4 text column',
        );
    }

    private function seedCourseSummary(int $courseId, string $summary): void
    {
        $this->ports->database->update('courses', ['summary' => $summary], ['id' => $courseId]);
    }
}
