<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateLayout;
use Shondalai\VidyaLMS\Core\Application\Credentials\ElementBox;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Availability\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementRegistry;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\SupportsTemplateFormats;
use Shondalai\VidyaLMS\Core\Registry\Context\CertificateRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;

/**
 * The free renderer for the element model.
 *
 * It exists because without it the seventeen free element providers,
 * certificate_template_pages and certificate_template_elements are exercised
 * by nothing at all: certificate_templates.renderer defaults to html and the
 * html renderer knows only a body and a token regex, so the elements format
 * would exist only behind a paid decorator. That is the empty-seam failure
 * the free-provider rule exists to prevent, and it is cheap to avoid.
 *
 * It walks pages in sequence, asks the layout service for each element's box
 * and calls the element's own renderHtml(). Nothing seeded uses it: the
 * seeded template stays the html format, so a site that installs nothing sees
 * exactly what it saw before.
 *
 * Two misses are handled here and both fail visible, which is the published
 * direction for a renderer. An element key no package supplies renders a
 * placeholder naming the missing key, and a payload above the provider's
 * supported version is treated identically, because a resolvable key carrying
 * an unreadable payload is the case a has() check cannot see.
 */
final class ElementsCertificateRenderer implements CertificateRendererInterface, SupportsTemplateFormats
{
    public function supportedTemplateFormats(): array
    {
        return ['elements'];
    }

    public const KEY = 'elements';

    public function __construct(
        private readonly CertificateElementRegistry $elements,
        private readonly TranslatorPort $translator,
        private readonly CertificateLayout $layout = new CertificateLayout(),
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.certificate_renderer.elements');
    }

    public function render(CertificateRenderContext $context): string
    {
        $tokens = $context->stringTokens();
        $pages = [];

        foreach ($context->pages as $page) {
            $pages[] = $this->renderPage($page, $tokens, $context);
        }

        return '<div class="vy-certificate" lang="' . htmlspecialchars(
            $context->locale,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8',
        ) . '">' . implode('', $pages) . '</div>';
    }

    /**
     * @param array<string, string> $tokens
     */
    private function renderPage(
        CertificateTemplatePage $page,
        array $tokens,
        CertificateRenderContext $context,
    ): string {
        $elements = $page->elements;

        // Draw order is the stored sequence, which is what puts a border
        // behind a name rather than over it.
        usort(
            $elements,
            static fn (CertificateTemplateElement $a, CertificateTemplateElement $b): int
                => [$a->sequence, $a->id] <=> [$b->sequence, $b->id],
        );

        $body = '';

        foreach ($elements as $element) {
            $body .= $this->renderElement($page, $element, $tokens, $context);
        }

        return '<section class="vy-certificate-page" style="'
            . $this->layout->pageStyle($page, $context->assetBaseUrl) . '">'
            . $body
            . '</section>';
    }

    /**
     * @param array<string, string> $tokens
     */
    private function renderElement(
        CertificateTemplatePage $page,
        CertificateTemplateElement $element,
        array $tokens,
        CertificateRenderContext $context,
    ): string {
        // The box is computed before either miss branch, because a placeholder
        // that does not occupy the space the element was authored into falls
        // into static flow at the page origin: two removed elements then stack
        // on each other over the top of the page. No element implementation
        // manages its own position, and the two absence shapes are elements
        // too.
        $box = $this->layout->boxFor($page, $element);

        if (!$this->elements->has($element->element)) {
            return $this->placeholder($element->element, $box);
        }

        $provider = $this->elements->get($element->element);
        $supported = $provider instanceof SupportsPayloadVersions
            ? $provider->supportedPayloadVersions()
            : [$provider->currentShape()];

        if (!\in_array($element->dataVersion, $supported, true)) {
            return $this->placeholder($element->element, $box);
        }

        $elementContext = ElementRenderContext::create(
            $page,
            $box,
            $context->now,
            $tokens,
            $context->locale,
            $context->timezone,
            $context->isPreview() ? ElementRenderContext::PURPOSE_PREVIEW : ElementRenderContext::PURPOSE_ISSUE,
            $context->assetBaseUrl,
        );

        $html = $provider->renderHtml($element->data, $elementContext);

        // The rule is the base class's, and this is what makes an element
        // that does not extend it inherit the rule anyway. An installed
        // provider returning nothing at all made the authored block vanish,
        // so installing a package could remove a line the free build had
        // drawn as a placeholder. A drawn-and-empty block is not the same
        // answer as an absent one, and the difference is the whole point.
        return trim($html) === ''
            ? $this->emptyBlock($element->element, $elementContext)
            : $html;
    }

    /**
     * The never-blank block for a provider that returned nothing, in the
     * element's own box so the page does not reflow around the gap.
     */
    private function emptyBlock(string $key, ElementRenderContext $context): string
    {
        $escaped = htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $inner = $context->isPreview() ? $escaped . ' (no_value)' : '';

        return '<div class="vy-cert-element vy-cert-element-empty'
            . ($context->isPreview() ? ' vy-cert-element-note' : '')
            . '" style="' . $context->box->style() . '"'
            . ' data-vy-element="' . $escaped . '" data-vy-empty="no_value">'
            . $inner
            . '</div>';
    }

    /**
     * A visible placeholder naming what is missing, never an exception and
     * never a blank: an unresolved renderer fails visible, so an author sees
     * that a package was removed instead of a certificate quietly losing a
     * line.
     */
    private function placeholder(string $key, ElementBox $box): string
    {
        $text = $this->translator->translate(
            'vidyalms.certificate_element.missing',
            ['element' => $key],
        );

        $escaped = htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // One attribute vocabulary for both absence shapes: a screen or a test
        // keyed on the empty block's data-vy-element silently missed the
        // missing one when the two spelled it differently.
        return '<div class="vy-cert-element vy-cert-element-missing" style="' . $box->style() . '"'
            . ' data-vy-element="' . $escaped . '" data-vy-empty="provider_missing"'
            . ' data-element="' . $escaped . '">'
            . htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>';
    }
}
