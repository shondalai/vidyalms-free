<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\RenderCertificate;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CredentialProviders;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Certificate\ElementsCertificateRenderer;
use Shondalai\VidyaLMS\Core\Provider\Certificate\HtmlCertificateRenderer;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\CatalogueTranslator;

/**
 * The mechanical proof that the snapshot actually froze.
 *
 * This is the single most important decision in the credentials area and the
 * one every peer gets wrong: Moodle's custom certificate regenerates from the
 * live template for ever, and LearnDash renders from current post content, so
 * correcting a template silently rewrites every certificate ever issued from
 * it. A prose claim is worth nothing here; the only thing that proves it is a
 * test that edits a template after issue and asserts the bytes did not move.
 */
final class FrozenSnapshotTest extends CredentialsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCourse(7, 'Advanced Weaving');
        $this->ports->users->add(5, 'Asha Learner');
    }

    public function testATemplateEditedAfterIssueLeavesTheCertificateByteIdentical(): void
    {
        $templateId = $this->templates->insert(
            'Original',
            'html',
            'Awarded to {learner_name} for {course_title}.',
            true,
            'published',
        );

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        self::assertTrue($certificate->hasSnapshot());

        $render = $this->renderUseCase();
        $before = $render->execute(Principal::user(5), $certificate->id, 'https://example.test/verify');

        // The correction every platform in the market applies retroactively.
        $this->ports->database->update(
            'certificate_templates',
            ['body' => 'CORRECTED. Awarded to {learner_name}.'],
            ['id' => $templateId],
        );

        $after = $render->execute(Principal::user(5), $certificate->id, 'https://example.test/verify');

        self::assertSame($before, $after);
        self::assertStringContainsString('Awarded to Asha Learner for Advanced Weaving.', $after);
        self::assertStringNotContainsString('CORRECTED', $after);
    }

    public function testARowIssuedBeforeTheSnapshotFallsBackToTheLiveTemplateAndSaysSo(): void
    {
        $templateId = $this->templates->insert(
            'Original',
            'html',
            'Awarded to {learner_name}.',
            true,
            'published',
        );

        // The unfrozen path, which is what every row issued before the
        // snapshot existed looks like.
        $certificate = $this->issueCertificateUseCase()->execute(10, 5, 7);
        self::assertFalse($certificate->hasSnapshot());

        $render = $this->renderUseCase();
        self::assertFalse($render->rendersFromSnapshot($certificate));

        $this->ports->database->update(
            'certificate_templates',
            ['body' => 'CORRECTED for {learner_name}.'],
            ['id' => $templateId],
        );

        $after = $render->execute(Principal::user(5), $certificate->id, 'https://example.test/verify');

        // It moved, which is the behaviour the fallback deliberately keeps
        // for rows it cannot do better for, and the response says which rows
        // those are.
        self::assertStringContainsString('CORRECTED for Asha Learner.', $after);
    }

    public function testTheSnapshotCarriesThePagesAndTheResolvedTokens(): void
    {
        $this->seedElementsTemplate([
            $this->element('learner_name', ['font' => ['family' => 'serif', 'size' => 30, 'weight' => 700]]),
            $this->element('verification_code', [], 2),
        ]);

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);

        $snapshot = $certificate->templateSnapshot;
        self::assertNotNull($snapshot);
        self::assertSame(1, $snapshot['_v']);

        $pages = $snapshot['pages'] ?? null;
        self::assertIsArray($pages);
        self::assertCount(1, $pages);
        self::assertCount(2, $pages[0]['elements']);

        $context = $certificate->contextSnapshot;
        self::assertNotNull($context);
        self::assertSame('Asha Learner', $context['tokens']['user.full_name']);
        self::assertSame('Advanced Weaving', $context['tokens']['course.title']);
        self::assertSame(
            'https://example.test/verify/' . $certificate->code,
            $context['tokens']['certificate.verification_url'],
        );
    }

    public function testTheElementsRendererDrawsEveryPageThroughTheLayoutService(): void
    {
        $this->seedElementsTemplate([
            $this->element('border', ['style' => 'solid', 'width_pt' => 4]),
            $this->element('learner_name', [], 2),
            $this->element('verification_code', [], 3),
        ]);

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $html = $this->renderUseCase()->execute(Principal::user(5), $certificate->id, 'https://example.test/verify');

        self::assertStringContainsString('vy-certificate-page', $html);
        self::assertStringContainsString('width: 297mm', $html);
        self::assertStringContainsString('Asha Learner', $html);
        self::assertStringContainsString('position: absolute', $html);
        self::assertStringContainsString($certificate->code, $html);
    }

    public function testAnUnknownElementRendersAPlaceholderNamingItRatherThanThrowing(): void
    {
        $templateId = $this->seedElementsTemplate([$this->element('learner_name')]);
        $pageId = (int) self::row($this->ports->database->selectOne(
            Query::from('certificate_template_pages')->where('template_id', '=', $templateId),
        ))['id'];

        $now = $this->ports->clock->now()->format('Y-m-d H:i:s');
        $this->ports->database->insert('certificate_template_elements', [
            'page_id' => $pageId,
            'sequence' => 5,
            'element' => 'acme.hologram',
            'data' => '{"_v":1}',
            'data_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $html = $this->renderUseCase()->execute(Principal::user(5), $certificate->id, 'https://example.test/verify');

        // An unresolved renderer fails visible. A throw out of the render
        // path would take the learner's download with it.
        self::assertStringContainsString('acme.hologram', $html);
        self::assertStringContainsString('vy-cert-element-missing', $html);
    }

    public function testARendererWhosePackageWasRemovedDegradesToAPlaceholder(): void
    {
        $this->templates->insert('Vanished', 'acme.pdf', 'body', true, 'published');

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $artefact = $this->renderUseCase()->renderArtefact(
            Principal::user(5),
            $certificate->id,
            'https://example.test/verify',
        );

        self::assertSame('text/html', $artefact->mediaType);
        self::assertStringContainsString('acme.pdf', $artefact->bytes);
    }

    public function testAnElementPayloadAboveTheSupportedVersionIsTreatedAsAMiss(): void
    {
        // A resolvable key carrying an unreadable payload is the case a plain
        // presence check cannot see: the key resolves, and a payload written
        // by a newer version reaches an evaluator that cannot read its own
        // fields.
        $this->seedElementsTemplate([$this->element('learner_name', [], 1, 9)]);

        $certificate = $this->issueWithSnapshot()->execute(10, 5, 7);
        $html = $this->renderUseCase()->execute(Principal::user(5), $certificate->id, 'https://example.test/verify');

        self::assertStringContainsString('vy-cert-element-missing', $html);
        self::assertStringNotContainsString('Asha Learner', $html);
    }

    private function renderUseCase(): RenderCertificate
    {
        // The real catalogue, so the miss messages under test are the copy a
        // site actually sees rather than a key echo.
        $translator = new CatalogueTranslator();
        $renderers = new CertificateRendererRegistry();
        $renderers->register(new HtmlCertificateRenderer($translator));
        $renderers->register(new ElementsCertificateRenderer($this->elementRegistry(), $translator));

        return new RenderCertificate(
            $this->certificates,
            $this->templates,
            $renderers,
            $this->courseTitles,
            $this->ports->users,
            $this->guard,
            $translator,
            $this->ports->locale,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function element(
        string $key,
        array $data = [],
        int $sequence = 1,
        int $version = 1,
    ): \Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement {
        $defaults = [];

        foreach (CredentialProviders::elements() as $element) {
            if ($element->key() === $key) {
                $defaults = $element->defaultPayload();
            }
        }

        return new \Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement(
            0,
            0,
            $key,
            $data + $defaults,
            $version,
            $sequence,
        );
    }
}
