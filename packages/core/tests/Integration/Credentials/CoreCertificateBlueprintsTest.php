<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateLayout;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTemplateChecks;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Pdf\PdfPort;
use Shondalai\VidyaLMS\Core\Provider\Certificate\CoreCertificateBlueprints;
use Shondalai\VidyaLMS\Core\Provider\Certificate\ElementsCertificateRenderer;
use Shondalai\VidyaLMS\Core\Provider\Certificate\PdfCertificateRenderer;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateTemplateBlueprint;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ProvidesReadableVerification;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RequiresAsset;
use Shondalai\VidyaLMS\Core\Registry\Context\CertificateRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementValidationContext;
use Shondalai\VidyaLMS\Core\Registry\Context\TemplateContext;
use Shondalai\VidyaLMS\Core\Tests\Support\CatalogueTranslator;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * Real free providers/resolvers over isolated in-memory ports. All identities
 * and verification addresses in these render fixtures are SYNTHETIC; no
 * credential is issued, gallery seeded, API added or live template changed.
 */
final class CoreCertificateBlueprintsTest extends TestCase
{
    private TestPorts $env;
    private CoreKernel $kernel;
    private CatalogueTranslator $translator;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->kernel = new CoreKernel($this->env->ports, [
            'site_name' => 'Synthetic Learning Institute',
            'site_url' => 'https://example.test',
            'verification_base_url' => 'https://example.test/verify',
        ]);
        $this->translator = new CatalogueTranslator();
    }

    public function testTheCatalogueHasNineStableDistinctVersionedDesignsWithResolvedMetadata(): void
    {
        $blueprints = $this->blueprints();
        self::assertSame([
            'vidyalms.certificate.executive_classic',
            'vidyalms.certificate.modern_professional',
            'vidyalms.certificate.minimal_academic',
            'vidyalms.certificate.aureate',
            'vidyalms.certificate.evergreen',
            'vidyalms.certificate.atelier',
            'vidyalms.certificate.prototype_classic',
            'vidyalms.certificate.prototype_modern',
            'vidyalms.certificate.prototype_executive',
        ], array_map(static fn (CertificateTemplateBlueprint $blueprint): string => $blueprint->key, $blueprints));
        $digests = [];

        foreach ($blueprints as $index => $blueprint) {
            self::assertSame(1, $blueprint->version);
            self::assertSame(1, $blueprint->schemaVersion);
            self::assertSame('a4_landscape', $blueprint->paperPreset);
            foreach ([$blueprint->nameKey, $blueprint->descriptionKey, $blueprint->categoryKey] as $key) {
                self::assertStringStartsWith('vidyalms.certificate_blueprint.', $key);
                self::assertNotSame($key, $this->translator->translate($key));
                self::assertNotSame('', trim($this->translator->translate($key)));
            }
            self::assertSame($this->translator->translate($blueprint->nameKey), $blueprint->source['name']);
            self::assertSame('en-GB', $blueprint->source['locale']);
            self::assertSame('elements', $blueprint->source['format']);
            self::assertSame('elements', $blueprint->source['renderer']);
            self::assertSame('CertificateOfCompletion', $blueprint->source['achievement_type']);
            self::assertSame($blueprint->source, $this->blueprints()[$index]->source);
            self::assertSame(hash('sha256', json_encode($blueprint->source, \JSON_THROW_ON_ERROR)), $blueprint->sourceDigest);
            $digests[] = $blueprint->sourceDigest;
        }

        self::assertCount(9, array_unique($digests));
        self::assertSame([2, 1, 0, 2, 2, 2, 2, 0, 2], array_map(
            static fn (CertificateTemplateBlueprint $blueprint): int => \count(array_filter(
                $blueprint->source['pages'][0]['elements'],
                static fn (array $element): bool => $element['element'] === 'border',
            )),
            $blueprints,
        ));
    }

    public function testVersionOneMaterializedSourcesCannotDriftWhenProviderDefaultsOrCopyChange(): void
    {
        // These are source-data fixtures, not normalized render hashes. An
        // intentional change needs a reviewed blueprint version/provenance
        // decision; a provider upgrade must not rewrite customized designs.
        self::assertSame([
            'e68bb01f3471adc61a35cbfd78412235f1d29b0873fb3a3624d85d6d49bdf20b',
            'cf4bebd32653b603c8e60d58ed3a8b6d8357391d89e9fa242627e48cc5edff7e',
            '04238c9ddc840e05ffdcb3c2747e4c37c7511834643c95d320f4d36939dba6fa',
            '86d304c620a4d25dd507e95bff5c0dca2c2389a7a71ebaca41f5a045d8273f31',
            'fed817e021c78745224e21031920d073648f252989a49e06c6673daf19c2c904',
            '11edfd2c5ae7d8041f0aaf54cc9812fa9609110496797673ca2061cd7db53970',
            'fd524872a2b283308b35f75fec89d24d7df39e056444790d0a2f671f87fa394a',
            '6f24cb0ee28bb283eed861757e14cfcfec79600cc8f0cb8184ce09bc1f880be2',
            'bc71402b5ae8eb2f977f2abdb014a28761b0ee760e02a81b598cc07c7de62fd7',
        ], array_map(static fn (CertificateTemplateBlueprint $blueprint): string => $blueprint->sourceDigest, $this->blueprints()));
    }

    public function testEveryCompletePayloadUsesAvailableFreeProvidersAndActualRegisteredTokensWithoutFindings(): void
    {
        $availableTokens = $this->kernel->certificateTokens->availableTokens();
        $checks = new CertificateTemplateChecks($this->kernel->registries->certificateElements, $this->kernel->certificateTokens);
        $pdf = new PdfCertificateRenderer(
            $this->kernel->registries->certificateElements,
            $this->translator,
            $this->createStub(PdfPort::class),
        );

        foreach ($this->blueprints() as $blueprint) {
            self::assertSame([], array_diff($blueprint->requiredTokens, $availableTokens));
            self::assertNotContains('user.email', $blueprint->requiredTokens);
            $actualElements = [];
            $readable = false;

            foreach ($this->pages($blueprint) as $page) {
                foreach ($page->elements as $element) {
                    $actualElements[] = $element->element;
                    self::assertTrue($this->kernel->registries->certificateElements->has($element->element));
                    $provider = $this->kernel->registries->certificateElements->get($element->element);
                    self::assertStringStartsWith('Shondalai\\VidyaLMS\\Core\\Provider\\Certificate\\Element\\', $provider::class);
                    self::assertNotInstanceOf(RequiresAsset::class, $provider);
                    self::assertSame(1, $element->dataVersion);
                    self::assertSame($provider->currentShape(), $element->dataVersion);
                    self::assertTrue($provider->isAvailable(TemplateContext::create(
                        format: 'elements',
                        renderer: 'elements',
                        locale: 'en-GB',
                        existingElements: $blueprint->requiredElements,
                    )));
                    self::assertSame([], $provider->validate($element->data, ElementValidationContext::create(
                        $element->element,
                        availableTokens: $availableTokens,
                    )), $blueprint->key . ': ' . $element->element);
                    self::assertSame([], array_diff(array_keys($provider->defaultPayload()), array_keys($element->data)));
                    $schema = $provider->formSchema();
                    self::assertSame([], array_diff(array_keys($element->data), array_keys($schema['fields'])));
                    self::assertNotSame('', trim($element->name ?? ''));
                    self::assertStringNotContainsString('vidyalms.', $element->name ?? '');

                    if ($provider instanceof ProvidesReadableVerification && $provider->emitsVerificationUrl($element->data)) {
                        $readable = true;
                    }
                }
            }

            self::assertSame($blueprint->requiredElements, array_values(array_unique($actualElements)));
            self::assertContains('learner_name', $actualElements);
            self::assertContains('course_name', $actualElements);
            self::assertContains('issue_date', $actualElements);
            self::assertContains('qr_code', $actualElements);
            self::assertTrue($readable, 'A QR cannot replace a readable address.');
            self::assertSame([], $checks->run($blueprint->source, $this->pages($blueprint)));
            self::assertSame([], $pdf->sourceFindings($blueprint->source, $this->pages($blueprint)));
        }
    }

    public function testEveryDeclaredBoxFitsExactA4AndNonDecorativeBoxesDoNotOverlap(): void
    {
        $layout = new CertificateLayout();

        foreach ($this->blueprints() as $blueprint) {
            $pages = $this->pages($blueprint);
            self::assertCount(1, $pages);
            $page = $pages[0];
            self::assertSame(297.0, $page->width);
            self::assertSame(210.0, $page->height);
            self::assertSame('landscape', $page->orientation);
            self::assertSame(1, $page->sequence);
            self::assertNull($page->background);
            self::assertSame(
                range(1, \count($page->elements)),
                array_map(static fn ($element): int => $element->sequence, $page->elements),
            );
            $contentBoxes = [];

            foreach ($page->elements as $element) {
                $box = $layout->boxFor($page, $element);
                self::assertNotNull($box->width);
                self::assertNotNull($box->height);
                self::assertGreaterThan(0, $box->width);
                self::assertGreaterThan(0, $box->height);
                self::assertGreaterThanOrEqual(0, $box->left);
                self::assertGreaterThanOrEqual(0, $box->top);
                self::assertLessThanOrEqual($page->width, $box->left + $box->width);
                self::assertLessThanOrEqual($page->height, $box->top + $box->height);
                if (\in_array($element->element, ['border', 'colour_panel'], true)) {
                    continue;
                }

                foreach ($contentBoxes as $previous) {
                    $overlap = $box->left < $previous->left + $previous->width
                        && $previous->left < $box->left + $box->width
                        && $box->top < $previous->top + $previous->height
                        && $previous->top < $box->top + $box->height;
                    self::assertFalse($overlap, $blueprint->key . ': overlapping declared content boxes');
                }
                $contentBoxes[] = $box;
            }
        }
    }

    public function testActualHtmlUsesSyntheticResolvedIdentitySelectableVerificationAndTheRealQr(): void
    {
        $this->env->users->add(42, 'Synthetic Learner Álvarez', 'synthetic@example.test');
        $tokens = $this->kernel->certificateTokens->resolve([
            'course_title' => 'Synthetic Leadership Programme',
            'issued_at' => '5 September 2026',
            'code' => 'ABCD2345EFGH',
        ], 42, null, 'en-GB');
        $renderer = new ElementsCertificateRenderer($this->kernel->registries->certificateElements, $this->translator);

        foreach ($this->blueprints() as $blueprint) {
            foreach ($blueprint->requiredTokens as $token) {
                self::assertArrayHasKey($token, $tokens);
                self::assertNotSame('', $tokens[$token]);
            }
            $context = CertificateRenderContext::create(
                certificate: [],
                template: $blueprint->source,
                tokens: $tokens,
                pages: $this->pages($blueprint),
                verificationUrl: $tokens['certificate.verification_url'],
                purpose: CertificateRenderContext::PURPOSE_PREVIEW,
                now: new \DateTimeImmutable('2026-09-05T00:00:00Z'),
            );
            $html = $renderer->render($context);
            self::assertSame($html, $renderer->render($context));
            self::assertStringContainsString('width: 297mm', $html);
            self::assertStringContainsString('height: 210mm', $html);
            self::assertStringContainsString('Synthetic Learner Álvarez', $html);
            self::assertStringContainsString('Synthetic Leadership Programme', $html);
            self::assertStringContainsString('Synthetic Learning Institute', $html);
            self::assertStringContainsString('5 September 2026', $html);
            self::assertStringContainsString('ABCD-2345-EFGH', $html);
            self::assertStringContainsString('href="https://example.test/verify/ABCD2345EFGH"', $html);
            self::assertStringContainsString('>https://example.test/verify/ABCD2345EFGH</a>', $html);
            self::assertStringContainsString('<svg', $html);
            self::assertStringContainsString('role="img"', $html);
            self::assertStringContainsString('The address is also printed as text.', $html);
            self::assertStringNotContainsString('vy-cert-element-note', $html);
            self::assertStringNotContainsString('synthetic@example.test', $html);
            self::assertStringNotContainsString('<img', $html);
            self::assertStringNotContainsString('<script', $html);
            self::assertDoesNotMatchRegularExpression('/\{[a-z_]+\.[a-z_]+\}/', $html);
            self::assertStringNotContainsString('vidyalms.certificate_blueprint.', $html);
        }
    }

    public function testVisibleCopyIsTranslatedBeforeSourceIsFrozenAndChangingACopyDoesNotChangeTheBlueprint(): void
    {
        $translator = new class ($this->translator) implements TranslatorPort {
            public function __construct(private readonly TranslatorPort $base)
            {
            }

            public function translate(string $key, array $parameters = []): string
            {
                return 'Translated: ' . $this->base->translate($key, $parameters);
            }
        };
        $translated = (new CoreCertificateBlueprints($translator, 'fr-FR'))->all();

        foreach ($translated as $index => $blueprint) {
            self::assertSame('fr-FR', $blueprint->source['locale']);
            self::assertStringStartsWith('Translated: ', $blueprint->source['name']);
            self::assertSame($this->blueprints()[$index]->key, $blueprint->key);
            self::assertNotSame($this->blueprints()[$index]->sourceDigest, $blueprint->sourceDigest);
            foreach ($blueprint->source['pages'][0]['elements'] as $element) {
                self::assertStringStartsWith('Translated: ', $element['name']);
                foreach (['text', 'label', 'alt'] as $field) {
                    $copy = $element['data'][$field] ?? null;
                    if (\is_string($copy) && $copy !== '' && $copy !== '{site.name}') {
                        self::assertStringStartsWith('Translated: ', $copy);
                    }
                }
            }
        }

        $original = $this->blueprints()[0];
        $editable = $original->source;
        $editable['pages'][0]['elements'][2]['data']['text'] = 'Operator customization';
        self::assertNotSame($editable, $original->source);
        self::assertSame($original->source, $this->blueprints()[0]->source);
    }

    public function testMaterializingBlueprintDataDoesNotSeedPublishIssueOrCarryOwnership(): void
    {
        $tables = ['certificate_templates', 'certificate_template_pages', 'certificate_template_elements', 'certificates'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = $this->env->database->count(Query::from($table));
        }

        foreach ($this->blueprints() as $blueprint) {
            $json = json_encode($blueprint->source, \JSON_THROW_ON_ERROR);
            self::assertDoesNotMatchRegularExpression(
                '/"(?:id|template_id|page_id|is_default|state|published_snapshot|locked_at)":/',
                $json,
            );
            self::assertDoesNotMatchRegularExpression('#https?://|data:|file:|url\(#i', $json);
            self::assertStringNotContainsString('Synthetic', $json);
            self::assertStringContainsString('{site.name}', $json);
        }

        foreach ($tables as $table) {
            self::assertSame($before[$table], $this->env->database->count(Query::from($table)));
        }
    }

    /** @return list<CertificateTemplateBlueprint> */
    private function blueprints(): array
    {
        return (new CoreCertificateBlueprints($this->translator, 'en-GB'))->all();
    }

    /** @return list<CertificateTemplatePage> */
    private function pages(CertificateTemplateBlueprint $blueprint): array
    {
        return array_map(CertificateTemplatePage::fromSnapshot(...), $blueprint->source['pages']);
    }
}
