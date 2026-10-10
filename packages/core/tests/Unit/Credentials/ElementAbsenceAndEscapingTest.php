<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Credentials;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateLayout;
use Shondalai\VidyaLMS\Core\Application\Credentials\ElementBox;
use Shondalai\VidyaLMS\Core\Boot\CredentialProviders;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Provider\Certificate\ElementsCertificateRenderer;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\CertificateRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;
use Shondalai\VidyaLMS\Core\Tests\Support\CatalogueTranslator;

/**
 * Two rules the renderer states and the elements did not keep.
 *
 * An installed element whose value is absent used to return the empty string,
 * so the authored block vanished from the document. That inverted the whole
 * direction of the design: with the paid package absent the free build drew a
 * visible placeholder naming the missing element, and with it installed the
 * same page drew nothing at all, so installing a package removed a line.
 *
 * And the typography and page background were interpolated into a
 * double-quoted style attribute without escaping, so a quote inside an
 * authored colour closed the attribute and everything after it became markup.
 */
final class ElementAbsenceAndEscapingTest extends TestCase
{
    public function testAnInstalledElementWithNoValueDrawsAnEmptyBlockAndNeverNothing(): void
    {
        $html = $this->render([
            new CertificateTemplateElement(1, 1, 'image', ['alt' => 'Seal'], 1),
        ]);

        self::assertStringContainsString('data-vy-element="image"', $html);
        self::assertStringContainsString('data-vy-empty="no_path"', $html);
    }

    public function testAProviderThatReturnsNothingAtAllStillLeavesTheBlockOnThePage(): void
    {
        $registry = $this->registry();
        $registry->register(new SilentElement());

        $html = $this->render(
            [new CertificateTemplateElement(1, 1, SilentElement::KEY, [], 1)],
            $registry,
        );

        // The rule lives here rather than only in the free base class, so an
        // element that does not extend it inherits the rule anyway. Eleven
        // elements across the free and paid builds returned '' the moment a
        // token was absent.
        self::assertStringContainsString('data-vy-element="' . SilentElement::KEY . '"', $html);
        self::assertStringContainsString('data-vy-empty="no_value"', $html);
    }

    public function testAnAuthoredColourCannotBreakOutOfTheStyleAttribute(): void
    {
        $element = $this->registry()->get('text');
        $payload = $element->defaultPayload();
        $payload['text'] = 'Awarded to R. Okafor';
        $payload['colour'] = '#000" onmouseover="alert(1)" x="';

        $html = $element->renderHtml($payload, $this->elementContext());

        self::assertStringNotContainsString('onmouseover="', $html, 'the style attribute was closed by the payload');
        self::assertStringContainsString('&quot;', $html);
    }

    public function testAnAuthoredFontFamilyCannotBreakOutOfTheStyleAttribute(): void
    {
        $element = $this->registry()->get('text');
        $payload = $element->defaultPayload();
        $payload['text'] = 'Awarded';
        $payload['font'] = ['family' => 'Georgia" onload="alert(1)', 'size' => 18, 'weight' => 600];

        $html = $element->renderHtml($payload, $this->elementContext());

        self::assertStringNotContainsString('onload="', $html, 'the style attribute was closed by the payload');
    }

    public function testAPageBackgroundCannotBreakOutOfTheStyleAttribute(): void
    {
        $page = new CertificateTemplatePage(
            0,
            1,
            0,
            297,
            210,
            'landscape',
            10,
            10,
            10,
            10,
            'x)" onmouseover="alert(document.domain)" data-x="',
        );

        $style = (new CertificateLayout())->pageStyle($page);

        self::assertStringNotContainsString('"', $style, 'a raw quote in the page style closes the attribute');
    }

    public function testAnAssetPathCannotReachAnotherHostOrClimbOutOfTheRoot(): void
    {
        $context = $this->elementContext();

        // A template author pointing an image element at an arbitrary host
        // turns every render, and the admin preview, into a beacon
        // disclosing the viewer's address on an evidentiary artefact.
        self::assertSame('', $context->assetUrl('https://tracker.example/pixel.png'));
        self::assertSame('', $context->assetUrl('//tracker.example/pixel.png'));
        self::assertSame('', $context->assetUrl('data:image/png;base64,AAAA'));
        self::assertSame('', $context->assetUrl('../../../etc/passwd'));
        self::assertSame(
            'https://example.test/assets/logos/seal.png',
            $context->assetUrl('logos/./seal.png'),
        );
    }

    /**
     * The two single-element cases above cover the one code path the shared
     * typography helper owns. This one covers every registered element,
     * because the element that broke out of the attribute was the one that
     * built its own style string and never called that helper.
     */
    public function testNoRegisteredElementCanBreakOutOfTheStyleAttribute(): void
    {
        $poison = '#000; } </style><b onmouseover="alert(1)" x="';

        foreach ($this->registry()->all() as $element) {
            $html = $element->renderHtml(
                self::poison($element->defaultPayload(), $poison),
                $this->elementContext(),
            );

            self::assertStringNotContainsString(
                'onmouseover="',
                $html,
                $element->key() . ' emitted an authored value into an attribute unescaped',
            );
            self::assertStringNotContainsString('</style>', $html, $element->key() . ' closed the style element');
        }
    }

    public function testAPageBackgroundCannotReachAnotherHostOrClimbOutOfTheRoot(): void
    {
        $layout = new CertificateLayout();

        // The same beacon the element asset resolver refuses. The page
        // background reaches the document through a different call site and
        // was the one that still took an absolute URL.
        self::assertStringNotContainsString(
            'beacon.example',
            $layout->pageStyle($this->pageWith('https://beacon.example.invalid/track.png'), 'https://example.test/assets'),
        );
        self::assertStringNotContainsString(
            'beacon.example',
            $layout->pageStyle($this->pageWith('//beacon.example.invalid/track.png'), 'https://example.test/assets'),
        );
        self::assertStringNotContainsString(
            'base64',
            $layout->pageStyle($this->pageWith('data:image/png;base64,AAAA'), 'https://example.test/assets'),
        );
        self::assertStringNotContainsString(
            'passwd',
            $layout->pageStyle($this->pageWith('../../../etc/passwd'), 'https://example.test/assets'),
        );
        self::assertStringContainsString(
            'url(https://example.test/assets/paper/parchment.png)',
            $layout->pageStyle($this->pageWith('paper/./parchment.png'), 'https://example.test/assets'),
        );
    }

    public function testAMissingElementPlaceholderKeepsTheBoxItWasAuthoredInto(): void
    {
        // cohort_name is a paid element, so the free registry is exactly the
        // shape a site that removed the package has.
        $html = $this->render(
            [new CertificateTemplateElement(1, 1, 'cohort_name', [], 1, 1, 'Cohort', 30, 150)],
        );

        // The placeholder used to be emitted before the layout service was
        // asked for the box, so a removed package's elements all stacked at
        // the page origin instead of standing where the author put them.
        self::assertStringContainsString('data-vy-element="cohort_name"', $html);
        self::assertStringContainsString('data-vy-empty="provider_missing"', $html);
        self::assertStringContainsString('left: 30mm', $html);
        self::assertStringContainsString('top: 150mm', $html);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function poison(array $payload, string $poison): array
    {
        foreach ($payload as $key => $value) {
            if (\is_string($value)) {
                $payload[$key] = $poison;
                continue;
            }

            if (\is_array($value)) {
                /** @var array<string, mixed> $value */
                $payload[$key] = self::poison($value, $poison);
            }
        }

        return $payload;
    }

    private function pageWith(string $background): CertificateTemplatePage
    {
        return new CertificateTemplatePage(0, 1, 0, 297, 210, 'landscape', 10, 10, 10, 10, $background);
    }

    /**
     * @param list<CertificateTemplateElement> $elements
     */
    private function render(array $elements, ?CertificateElementRegistry $registry = null): string
    {
        $page = new CertificateTemplatePage(0, 1, 0, 297, 210, 'landscape');
        $page = $page->withElements($elements);

        $renderer = new ElementsCertificateRenderer(
            $registry ?? $this->registry(),
            new CatalogueTranslator(),
        );

        return $renderer->render(CertificateRenderContext::create(
            [],
            [],
            [],
            'en-GB',
            'UTC',
            [$page],
            '',
            CertificateRenderContext::PURPOSE_ISSUE,
            false,
            'https://example.test/assets',
            new \DateTimeImmutable('2026-05-05 12:00:00'),
        ));
    }

    private function registry(): CertificateElementRegistry
    {
        $registry = new CertificateElementRegistry();

        foreach (CredentialProviders::elements() as $element) {
            $registry->register($element);
        }

        return $registry;
    }

    private function elementContext(): ElementRenderContext
    {
        return ElementRenderContext::create(
            new CertificateTemplatePage(0, 1),
            ElementBox::of(0.0, 0.0, null, null, 'left'),
            new \DateTimeImmutable('2026-05-05 12:00:00'),
            [],
            'en-GB',
            'UTC',
            ElementRenderContext::PURPOSE_ISSUE,
            'https://example.test/assets',
        );
    }
}
