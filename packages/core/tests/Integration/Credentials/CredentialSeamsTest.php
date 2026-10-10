<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\ElementBox;
use Shondalai\VidyaLMS\Core\Application\Credentials\GetCredential;
use Shondalai\VidyaLMS\Core\Application\Credentials\RenderCertificate;
use Shondalai\VidyaLMS\Core\Application\Credentials\StatusListDocument;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\Handler\GetCredentialStatusListHandler;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Provider\Certificate\HtmlCertificateRenderer;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\RenderCertificateTask;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererRegistry;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Provider\Credential\VidyaLmsJsonFormat;
use Shondalai\VidyaLMS\Core\Provider\Token\CreditTokenResolver;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CourseCredit;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusList;
use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Context\CredentialContext;
use Shondalai\VidyaLMS\Core\Registry\Context\IssuanceParameterContext;
use Shondalai\VidyaLMS\Core\Registry\Context\TokenContext;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialArtefact;
use Shondalai\VidyaLMS\Core\Registry\Credential\CredentialFormatInterface;
use Shondalai\VidyaLMS\Core\Registry\Credential\DeclaresFormatPreference;
use Shondalai\VidyaLMS\Core\Registry\Credit\ComputesFromParams;
use Shondalai\VidyaLMS\Core\Registry\Credit\CreditSchemeInterface;
use Shondalai\VidyaLMS\Core\Registry\Expiry\ExpiryContext;
use Shondalai\VidyaLMS\Core\Registry\Expiry\ExpiryPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Token\ContributesIssuanceParameters;
use Shondalai\VidyaLMS\Core\Registry\Token\TokenResolverInterface;

/**
 * The four credential seams a third party is invited to register into, each
 * exercised by a provider this test registers and nothing else does.
 *
 * Every case here is a registration that changed nothing observable before
 * the fix: the parameter bag was a static method with a closed argument list,
 * the credit arithmetic never probed its own capability, negotiation was
 * first match on a media type three formats share, and expiry was an
 * if-chain over three literals duplicated in two use cases.
 */
final class CredentialSeamsTest extends CredentialsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCourse(7, 'Advanced Weaving');
        $this->ports->users->add(5, 'Asha Learner', 'asha@example.test');
    }

    public function testATokenFamilySuppliesItsOwnInputsAndReadsThemBack(): void
    {
        $this->tokenResolvers()->register(new FakeContributingResolver());

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $stored = $this->certificates->findById($certificate->id);

        self::assertNotNull($stored);

        $tokens = $stored->contextSnapshot['tokens'] ?? [];

        self::assertIsArray($tokens);
        // Before the contributor seam the bag was whatever one static method
        // assembled, so a family whose inputs it did not happen to build
        // could never resolve at all: four shipped families read keys
        // nothing wrote.
        self::assertSame('Advanced Weaving/5', $tokens['fake.echo'] ?? null);
    }

    public function testTheParameterContextCarriesEveryCreditRowAndNotOnlyTheFirst(): void
    {
        $this->creditSchemes()->register(new FakeSecondScheme());
        $this->seedCourseCredit(7, 120);
        $this->courseCredits()->save(new CourseCredit(7, FakeSecondScheme::KEY, ['contact_minutes' => 300], 1, 1));

        $resolver = new FakeContributingResolver();
        $this->tokenResolvers()->register($resolver);

        $this->issueWithSnapshot()->execute(10, 5, 7);

        // A flat family keyed off $credits[0] reported one scheme and dropped
        // the rest, which on a course carrying both IACET CEUs and NASBA CPE
        // silently prints one of them on the document a state board reviews.
        self::assertSame(2, $resolver->creditCount);
    }

    public function testATwoSchemeCourseStatesBothSchemesInTheFreezeAndOnTheCertificate(): void
    {
        $this->creditSchemes()->register(new FakeSecondScheme());
        $this->seedCourseCredit(7, 120, 'Accounting');
        $this->courseCredits()->save(new CourseCredit(7, FakeSecondScheme::KEY, ['contact_minutes' => 300], 1, 1));

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $stored = $this->certificates->findById($certificate->id);

        self::assertNotNull($stored);

        $tokens = $stored->contextSnapshot['tokens'] ?? [];
        self::assertIsArray($tokens);

        $rows = CreditTokenResolver::decodeRows(
            \is_string($tokens[CreditTokenResolver::TOKEN_ROWS] ?? null)
                ? $tokens[CreditTokenResolver::TOKEN_ROWS]
                : null,
        );

        self::assertCount(2, $rows, 'the frozen bag carried only the first credit row');

        $element = $this->elementRegistry()->get('credit_statement');
        $html = $element->renderHtml(
            $element->defaultPayload(),
            ElementRenderContext::create(
                new CertificateTemplatePage(1, 1),
                ElementBox::of(0.0, 0.0, null, null, 'left'),
                $this->ports->clock->now(),
                array_map(static fn (mixed $value): string => (string) $value, $tokens),
            ),
        );

        foreach ($rows as $row) {
            self::assertStringContainsString($row['amount'], $html);
            self::assertStringContainsString($row['unit'], $html);
        }
    }

    public function testASchemeWhoseAmountIsNotAFunctionOfMinutesComputesItFromItsOwnParameters(): void
    {
        $this->creditSchemes()->register(new FakeParameterisedScheme());
        $this->courseCredits()->save(new CourseCredit(
            7,
            FakeParameterisedScheme::KEY,
            ['contact_minutes' => 130, 'increment' => 'fifth'],
            1,
            1,
        ));

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $credits = $this->certificateCredits()->forCertificate($certificate->id);

        self::assertCount(1, $credits);
        // Without the probe the row stored amountFor()'s conservative default
        // and the elected increment sat in params, consulted by nothing.
        self::assertSame('2.60', $credits[0]->amount);
    }

    public function testTheHighestRankedFormatWinsAmongThoseSharingOneMediaType(): void
    {
        $this->credentialFormats()->register(new FakeSignedJsonFormat());

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $credentials = new GetCredential($this->certificates, $this->credentialFormats(), $this->credentialAssembly());

        $served = $credentials->execute((string) $certificate->uuid, ['application/ld+json']);

        self::assertNotNull($served);
        // First match on the media type meant registration order was the
        // whole selection policy and the free build registers first, so a
        // signing package was a registration that changed nothing a relying
        // party could observe.
        self::assertSame(FakeSignedJsonFormat::KEY, $served->formatKey);
        self::assertSame('FakeProof2026', $served->proofType);
    }

    public function testAFormatThatCannotServeOnThisSiteIsANegotiationMissAndNotAnError(): void
    {
        $unavailable = new FakeSignedJsonFormat(false);
        $this->credentialFormats()->register($unavailable);

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $credentials = new GetCredential($this->certificates, $this->credentialFormats(), $this->credentialAssembly());

        $served = $credentials->execute((string) $certificate->uuid, ['application/ld+json']);

        // A signing package installed with no key configured used to throw
        // out of build(), escape the use case and reach the caller as a 500,
        // so a licensed site was worse off than a free one: the free site
        // answered the designed 406 and named what it could serve.
        self::assertNotNull($served);
        self::assertSame(VidyaLmsJsonFormat::KEY, $served->formatKey);
    }

    public function testAnUnavailableFormatIsAbsentFromWhatTheBuildSaysItCanServe(): void
    {
        $this->credentialFormats()->register(new FakeUnservableFormat());

        $credentials = new GetCredential($this->certificates, $this->credentialFormats(), $this->credentialAssembly());

        self::assertNotContains('application/vc+jwt', $credentials->servableMediaTypes());
    }

    public function testEachMediaTypeIsNamedOnceInWhatTheBuildCanServe(): void
    {
        $this->credentialFormats()->register(new FakeSignedJsonFormat());

        $credentials = new GetCredential($this->certificates, $this->credentialFormats(), $this->credentialAssembly());

        $servable = $credentials->servableMediaTypes();

        self::assertSame($servable, array_values(array_unique($servable)));
        self::assertContains('application/ld+json', $servable);
    }

    public function testARegisteredExpiryPolicyIsResolvedAtIssueAndFrozenIntoTheSnapshot(): void
    {
        $this->expiryPolicies()->register(new FakeAnniversaryPolicy());

        $templateId = $this->templates->insert(
            'Anniversary',
            'html',
            'Body {certificate.expires_at}',
            true,
            'published',
        );
        $this->ports->database->update(
            'certificate_templates',
            ['expiry_mode' => FakeAnniversaryPolicy::KEY],
            ['id' => $templateId],
        );

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $stored = $this->certificates->findById($certificate->id);

        self::assertNotNull($stored);
        self::assertNotNull($stored->expiresAt, 'A registered policy answered nothing at issue.');

        // The freeze has to carry it too. A row issued with no expiry and
        // stamped by a daily sweep afterwards reports an expiry the holder's
        // own certificate never mentioned.
        $tokens = $stored->contextSnapshot['tokens'] ?? [];
        self::assertIsArray($tokens);
        self::assertNotSame('', (string) ($tokens['certificate.expires_at'] ?? ''));
    }

    public function testAnUnresolvableExpiryModeMeansNeverExpiresRatherThanAnError(): void
    {
        $templateId = $this->templates->insert('Ghost', 'html', 'Body', true, 'published');
        $this->ports->database->update(
            'certificate_templates',
            ['expiry_mode' => 'policy.from.a.package.nobody.installed'],
            ['id' => $templateId],
        );

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);

        self::assertNull($certificate->expiresAt);
    }

    public function testTheQueuedRenderWritesAnArtefactSoTheDownloadConflictIsTransient(): void
    {
        $this->templates->insert('Standard', 'html', 'Awarded to {user.full_name}', true, 'published');

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);

        self::assertNull($certificate->artefactPath, 'the download is 409 until the queued task runs');

        $queued = array_values(array_filter(
            $this->ports->scheduler->tasks,
            static fn (ScheduledTask $task): bool => $task->type === RenderCertificateTask::KEY,
        ));

        self::assertCount(1, $queued);

        $renderers = new CertificateRendererRegistry();
        $renderers->register(new HtmlCertificateRenderer($this->ports->translator));

        $task = new RenderCertificateTask(
            new RenderCertificate(
                $this->certificates,
                $this->templates,
                $renderers,
                $this->courseTitles,
                $this->ports->users,
                $this->guard,
                $this->ports->translator,
            ),
            $this->ports->storage,
            'https://example.test/verify',
            $this->certificates,
            $this->ports->clock,
        );

        $task->run(new ScheduledTaskRun(1, $queued[0]->payload, Principal::guest()));

        $rendered = $this->certificates->findById($certificate->id);

        self::assertNotNull($rendered);
        // markRendered() had exactly one caller and nothing queued that
        // caller, so every certificate on both live sites carried a null
        // artefact_path and the learner's download answered 409 for ever.
        self::assertNotNull($rendered->artefactPath);
        self::assertNotNull($rendered->artefactChecksum);
        self::assertNotNull($rendered->renderedAt);
    }

    public function testAQueuedRenderForARevokedCertificateIsSkippedRatherThanThrown(): void
    {
        $this->templates->insert('Standard', 'html', 'Awarded to {user.full_name}', true, 'published');

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);

        $queued = array_values(array_filter(
            $this->ports->scheduler->tasks,
            static fn (ScheduledTask $task): bool => $task->type === RenderCertificateTask::KEY,
        ));
        self::assertCount(1, $queued);

        $this->certificates->markRevoked($certificate->id, $this->ports->clock->now(), 'issued_in_error');

        $renderers = new CertificateRendererRegistry();
        $renderers->register(new HtmlCertificateRenderer($this->ports->translator));

        $task = new RenderCertificateTask(
            new RenderCertificate(
                $this->certificates,
                $this->templates,
                $renderers,
                $this->courseTitles,
                $this->ports->users,
                $this->guard,
                $this->ports->translator,
                clock: $this->ports->clock,
            ),
            $this->ports->storage,
            'https://example.test/verify',
            $this->certificates,
            $this->ports->clock,
        );

        // Issue, revoke the same day, cron runs is an ordinary sequence, and
        // the exception path of a task handler is reserved for programmer
        // error: a throw here leaves a permanently failing job row.
        $message = $task->run(new ScheduledTaskRun(1, $queued[0]->payload, Principal::guest()));

        self::assertStringContainsString('skipped=revoked', $message);

        $rendered = $this->certificates->findById($certificate->id);
        self::assertNotNull($rendered);
        self::assertNull($rendered->artefactPath, 'a withdrawn certificate got an artefact anyway');
    }

    public function testAStatusListThatWentStaleIsRefusedRatherThanServedAsAuthoritative(): void
    {
        $this->issueWithSnapshot()->execute(10, 5, 7);

        $list = $this->credentialStatus()->findList(1);
        self::assertNotNull($list);

        $published = $this->ports->clock->now();
        $this->credentialStatus()->clearDirty($list->id, 'H4sIAAAAAAAAA', $published);
        $this->credentialStatus()->markDirty(
            IssuanceRecord::SUBJECT_CERTIFICATE,
            1,
            $published->modify('-2 days'),
        );

        $handler = new GetCredentialStatusListHandler(
            $this->credentialStatus(),
            $this->ports->clock,
            'https://example.test/api/v1/credentials/status',
        );

        $fresh = $this->credentialStatus()->findListByUuid($list->listUuid);
        self::assertNotNull($fresh);

        // Every publisher is a paid provider and every lifecycle write that
        // dirties a list is free, so a site that publishes once and removes
        // the package would otherwise keep serving a frozen bitstring as
        // authoritative while recording revocations against it.
        $this->expectException(ApiProblem::class);

        $handler->handle(new ApiRequest(
            'GET',
            '/credentials/status/' . $list->listUuid,
            Principal::guest(),
            pathParams: ['listId' => $list->listUuid],
        ));
    }

    public function testTheStatusListDocumentIsOneAVerifierCanGetPastStepOneOf(): void
    {
        $list = new CredentialStatusList(
            3,
            'revocation',
            'list-uuid-1',
            131072,
            1,
            'H4sIAAAAAAAAA',
            300000,
            null,
            new \DateTimeImmutable('2026-05-05 12:00:00'),
        );

        $document = StatusListDocument::build(
            $list,
            'https://example.test/api/v1/credentials/status',
            'https://northlight.example',
        );

        // The verifier algorithm starts by dereferencing the credential,
        // which needs an id, a type and an issuer. The route served a body
        // carrying none of the three.
        self::assertSame(
            'https://example.test/api/v1/credentials/status/list-uuid-1',
            $document['id'],
        );
        self::assertContains(StatusListDocument::TYPE_CREDENTIAL, $document['type']);
        self::assertSame('https://northlight.example', $document['issuer']);
        self::assertArrayHasKey('validFrom', $document);

        $subject = $document['credentialSubject'];
        self::assertIsArray($subject);
        self::assertSame($document['id'] . '#list', $subject['id']);
        self::assertSame('revocation', $subject['statusPurpose']);
    }

    public function testAnUnsecuredStatusListDoesNotClaimToBeAVerifiableCredential(): void
    {
        $list = new CredentialStatusList(
            3,
            'revocation',
            'list-uuid-1',
            131072,
            1,
            'H4sIAAAAAAAAA',
            300000,
            null,
            new \DateTimeImmutable('2026-05-05 12:00:00'),
        );

        $unsecured = StatusListDocument::build($list, 'https://example.test/status', 'https://northlight.example');

        // Step two of the published verifier algorithm is "verify its
        // proofs". A document that names itself a VerifiableCredential and
        // carries none makes a conforming verifier fail the status check and
        // report a failure that is ours rather than the wallet's, which is
        // the same reason vidyalms.json refuses the type.
        self::assertNotContains('VerifiableCredential', $unsecured['type']);
        self::assertArrayNotHasKey('proof', $unsecured);

        $secured = StatusListDocument::build(
            $list,
            'https://example.test/status',
            'https://northlight.example',
            ['type' => 'DataIntegrityProof', 'cryptosuite' => 'eddsa-jcs-2022', 'proofValue' => 'z5oV'],
        );

        self::assertContains('VerifiableCredential', $secured['type']);
        self::assertArrayHasKey('proof', $secured);
    }

    public function testTheSubjectTypesTheStatusTableServesAreBothNamed(): void
    {
        self::assertSame('certificate', IssuanceRecord::SUBJECT_CERTIFICATE);
        self::assertSame('badge_award', IssuanceRecord::SUBJECT_BADGE_AWARD);
    }
}
