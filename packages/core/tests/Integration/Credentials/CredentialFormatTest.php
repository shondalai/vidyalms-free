<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\CredentialAssembly;
use Shondalai\VidyaLMS\Core\Application\Credentials\CredentialElementClaims;
use Shondalai\VidyaLMS\Core\Application\Credentials\GetCredential;
use Shondalai\VidyaLMS\Core\Application\Credentials\Png\PngBaker;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Provider\Credential\OpenBadges2AssertionFormat;
use Shondalai\VidyaLMS\Core\Provider\Credential\OpenBadges2BakedPngFormat;
use Shondalai\VidyaLMS\Core\Provider\Credential\VidyaLmsJsonFormat;
use Shondalai\VidyaLMS\Core\Registry\Context\CredentialContext;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialFormatRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\CatalogueTranslator;

/**
 * The two free credential formats, and the claim that the unsigned body is
 * one signature away from conformant.
 *
 * That claim is machine-checked rather than asserted: the test takes the same
 * builder output the endpoint serves, injects the W3C and Open Badges
 * contexts and the two types, and checks the required-property sets the
 * published Open Badges 3.0 AchievementCredential schema declares. Reading a
 * specification and believing a docblock is how a body ships one property
 * short.
 */
final class CredentialFormatTest extends CredentialsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCourse(7, 'Advanced Weaving');
        $this->ports->users->add(5, 'Asha Learner', 'asha@example.test');
    }

    public function testTheFreeBodyCarriesEveryRequiredOpenBadgesProperty(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $record = $certificate->toIssuanceRecord(
            'CertificateOfCompletion',
            $this->certificateCredits()->forCertificate($certificate->id),
        );

        $format = new VidyaLmsJsonFormat(new CatalogueTranslator());
        $body = $format->body($record, $this->context($certificate->id));

        // The conformance shape: the same bytes, with the standard contexts
        // and types injected.
        $conformant = $body;
        $conformant['@context'] = [
            'https://www.w3.org/ns/credentials/v2',
            'https://purl.imsglobal.org/spec/ob/v3p0/context-3.0.3.json',
        ];
        $conformant['type'] = ['VerifiableCredential', 'OpenBadgeCredential', 'AchievementCredential'];

        foreach (['@context', 'id', 'type', 'credentialSubject', 'issuer', 'validFrom'] as $required) {
            self::assertArrayHasKey($required, $conformant, 'AchievementCredential requires ' . $required);
        }

        $subject = $conformant['credentialSubject'];
        self::assertIsArray($subject);

        foreach (['type', 'achievement'] as $required) {
            self::assertArrayHasKey($required, $subject, 'AchievementSubject requires ' . $required);
        }

        $achievement = $subject['achievement'];
        self::assertIsArray($achievement);

        foreach (['id', 'type', 'criteria', 'description', 'name'] as $required) {
            self::assertArrayHasKey($required, $achievement, 'Achievement requires ' . $required);
        }

        $identity = $subject['identifier'][0] ?? null;
        self::assertIsArray($identity);

        foreach (['type', 'hashed', 'identityHash', 'identityType'] as $required) {
            self::assertArrayHasKey($required, $identity, 'IdentityObject requires ' . $required);
        }

        // Never a plaintext address, on a document made to be shared.
        self::assertStringStartsWith('sha256$', (string) $identity['identityHash']);
        self::assertStringNotContainsString('asha@example.test', json_encode($body, JSON_THROW_ON_ERROR));
    }

    public function testTheUnsignedBodyDoesNotClaimToBeAVerifiableCredential(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $format = new VidyaLmsJsonFormat(new CatalogueTranslator());
        $body = $format->body($certificate->toIssuanceRecord(), $this->context($certificate->id));

        // An unsigned document opening with the W3C context invites a wallet
        // to attempt verification of something with no securing mechanism and
        // to report a failure that is our fault rather than the wallet's.
        self::assertSame([VidyaLmsJsonFormat::CONTEXT], $body['@context']);
        self::assertNotContains('VerifiableCredential', $body['type']);

        $artefact = $format->build($certificate->toIssuanceRecord(), $this->context($certificate->id));
        self::assertTrue($artefact->isUnsigned());
        self::assertSame('application/ld+json', $artefact->mediaType());
    }

    public function testTheStatusEntriesReachTheCredentialBodyOncePerPurpose(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $format = new VidyaLmsJsonFormat(new CatalogueTranslator());
        $body = $format->body($certificate->toIssuanceRecord(), $this->context($certificate->id));

        $status = $body['credentialStatus'] ?? [];
        self::assertIsArray($status);
        self::assertCount(2, $status);
        self::assertSame('revocation', $status[0]['statusPurpose']);
        self::assertSame('suspension', $status[1]['statusPurpose']);
        // A credential never carries a database key.
        self::assertStringNotContainsString('list_id', json_encode($status, JSON_THROW_ON_ERROR));
    }

    public function testTheBakedBadgeCarriesTheAssertionInsideThePng(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $format = new OpenBadges2BakedPngFormat(new CatalogueTranslator());
        $artefact = $format->build($certificate->toIssuanceRecord(), $this->context($certificate->id));

        self::assertSame('image/png', $artefact->mediaType());
        self::assertStringStartsWith("\x89PNG\r\n\x1a\n", $artefact->bytes());

        $baked = (new PngBaker())->extract($artefact->bytes());
        self::assertNotNull($baked);

        $assertion = json_decode($baked, true);
        self::assertIsArray($assertion);
        self::assertSame('Assertion', $assertion['type']);
        self::assertSame(['type' => 'HostedBadge'], $assertion['verification']);
        self::assertArrayHasKey('recipient', $assertion);
        self::assertArrayHasKey('badge', $assertion);
        self::assertStringContainsString((string) $certificate->uuid, (string) $assertion['id']);
    }

    public function testTheAddressTheBadgeBakesServesAnOpenBadges2Assertion(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);

        $baked = (new PngBaker())->extract(
            (new OpenBadges2BakedPngFormat(new CatalogueTranslator()))
                ->build($certificate->toIssuanceRecord(), $this->context($certificate->id))
                ->bytes(),
        );
        self::assertNotNull($baked);

        /** @var array<string, mixed> $chunk */
        $chunk = json_decode($baked, true, 8, JSON_THROW_ON_ERROR);
        $assertionUrl = (string) $chunk['id'];

        // The address the badge names, resolved the way a hosted verifier
        // resolves it: it dereferences the URL asking for JSON and expects an
        // Assertion. It used to answer 406.
        $uuid = basename((string) parse_url($assertionUrl, PHP_URL_PATH));
        $artefact = $this->getCredential()->execute($uuid, ['application/json']);

        self::assertNotNull($artefact, 'the baked assertion URL served nothing a verifier can read');
        self::assertSame('application/json', $artefact->mediaType());

        /** @var array<string, mixed> $document */
        $document = json_decode($artefact->bytes(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(OpenBadges2AssertionFormat::CONTEXT, $document['@context']);
        self::assertSame('Assertion', $document['type']);
        self::assertSame($assertionUrl, $document['id']);
        self::assertSame(['type' => 'HostedBadge'], $document['verification']);
        self::assertArrayHasKey('issuedOn', $document);

        /** @var array<string, mixed> $recipient */
        $recipient = $document['recipient'];
        self::assertTrue($recipient['hashed']);
        self::assertStringStartsWith('sha256$', (string) $recipient['identity']);

        /** @var array<string, mixed> $badge */
        $badge = $document['badge'];
        self::assertSame('BadgeClass', $badge['type']);

        foreach (['id', 'name', 'description', 'criteria', 'issuer'] as $required) {
            self::assertArrayHasKey($required, $badge, 'BadgeClass requires ' . $required);
        }
    }

    public function testABakeReplacesRatherThanAppends(): void
    {
        $baker = new PngBaker();
        $once = $baker->bake($baker->canvas(8, 8), 'first');
        $twice = $baker->bake($once, 'second');

        // Two openbadges chunks in one file is a badge that verifies
        // differently depending on which one a reader finds first.
        self::assertSame('second', $baker->extract($twice));
        self::assertSame(1, substr_count($twice, 'openbadges'));
    }

    public function testAFormatThisBuildCannotServeIsAbsentFromNegotiationRatherThanAnError(): void
    {
        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $credentials = $this->getCredential();

        self::assertNull($credentials->execute((string) $certificate->uuid, ['application/vc+jwt']));

        $json = $credentials->execute((string) $certificate->uuid, ['application/ld+json']);
        self::assertNotNull($json);
        self::assertSame('application/ld+json', $json->mediaType());

        $png = $credentials->execute((string) $certificate->uuid, ['image/png']);
        self::assertNotNull($png);
        self::assertSame('image/png', $png->mediaType());

        // No preference is not a refusal.
        $default = $credentials->execute((string) $certificate->uuid, []);
        self::assertNotNull($default);

        // The three the free build serves. application/json is the Open
        // Badges 2.0 hosted assertion the baked image points at, which used
        // to be a 406 on the address the badge names.
        self::assertSame(
            ['application/ld+json', 'image/png', 'application/json'],
            $credentials->servableMediaTypes(),
        );
    }

    private function getCredential(): GetCredential
    {
        $formats = new CredentialFormatRegistry();
        $translator = new CatalogueTranslator();
        $formats->register(new VidyaLmsJsonFormat($translator));
        $formats->register(new OpenBadges2BakedPngFormat($translator));
        $formats->register(new OpenBadges2AssertionFormat($translator));

        return new GetCredential($this->certificates, $formats, $this->credentialAssembly());
    }

    protected function credentialAssembly(): CredentialAssembly
    {
        return new CredentialAssembly(
            $this->courseTitles,
            $this->ports->clock,
            $this->certificateCredits(),
            $this->credentialStatus(),
            $this->templates,
            new Settings(new DatabaseSettingsRepository($this->ports->database, $this->ports->clock)),
            'https://example.test/api/v1/credentials',
            'https://example.test/verify',
            'https://example.test/api/v1/credentials/status',
            new CredentialElementClaims($this->elementRegistry()),
        );
    }

    public function testTheMergedBodyOfACreditBearingTemplateStaysConformant(): void
    {
        $this->seedCourseCredit(7, 360, 'Accessibility');
        $this->seedElementsTemplate([
            new CertificateTemplateElement(0, 0, 'credit_statement', [
                'template' => '{credit.amount} {credit.unit}',
            ]),
        ]);

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $stored = $this->certificates->findById($certificate->id) ?? self::fail('no row');

        // Through the assembly rather than a hand-built context, so the
        // element claims really are merged: the conformance case never drove
        // a certificate carrying a credit element, so the injected-schema
        // validation never saw the merged body at all.
        $format = new VidyaLmsJsonFormat(new CatalogueTranslator());
        $body = $format->body(
            $stored->toIssuanceRecord(
                'CertificateOfCompletion',
                $this->certificateCredits()->forCertificate($stored->id),
            ),
            $this->credentialAssembly()->contextFor($stored),
        );

        $subject = $body['credentialSubject'];
        self::assertIsArray($subject);

        $achievement = $subject['achievement'];
        self::assertIsArray($achievement);

        // Open Badges 3.0 puts creditsEarned on AchievementSubject and
        // declares it a number. Achievement defines no such term, so a claim
        // there is dropped or errors under JSON-LD expansion.
        self::assertArrayNotHasKey('creditsEarned', $achievement);
        self::assertArrayHasKey('creditsEarned', $subject);
        self::assertIsFloat($subject['creditsEarned']);
        self::assertSame(6.0, $subject['creditsEarned']);

        foreach (['id', 'type', 'criteria', 'description', 'name'] as $required) {
            self::assertArrayHasKey($required, $achievement, 'Achievement requires ' . $required);
        }
    }

    private function context(int $certificateId): CredentialContext
    {
        $entries = [];

        foreach ($this->credentialStatus()->entriesFor('certificate', $certificateId) as $entry) {
            $entries[] = $entry->toCredentialEntry();
        }

        return CredentialContext::create(
            $this->ports->clock->now(),
            ['name' => 'Northlight', 'url' => 'https://example.test'],
            'https://example.test/api/v1/credentials',
            'https://example.test/verify',
            'https://example.test/api/v1/credentials/status',
            'Advanced Weaving',
            'A course about weaving.',
            null,
            null,
            $entries,
        );
    }
}
