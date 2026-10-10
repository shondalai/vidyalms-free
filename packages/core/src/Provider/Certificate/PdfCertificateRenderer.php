<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateLayout;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificatePdfAssets;
use Shondalai\VidyaLMS\Core\Application\Credentials\Qr\QrEncoder;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Pdf\PdfDocument;
use Shondalai\VidyaLMS\Core\Port\Pdf\PdfPort;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\TokenTextElement;
use Shondalai\VidyaLMS\Core\Registry\Availability\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateElementRegistry;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\InspectsCertificateSource;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RendersToBinary;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ResolvesToText;
use Shondalai\VidyaLMS\Core\Registry\Certificate\SupportsTemplateFormats;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ValidationFinding;
use Shondalai\VidyaLMS\Core\Registry\Context\CertificateRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;

/** One structured document feeds real PDF preview and issued artifacts. No authored HTML is executed. */
final class PdfCertificateRenderer implements
    CertificateRendererInterface,
    RendersToBinary,
    SupportsTemplateFormats,
    InspectsCertificateSource
{
    public const KEY = 'pdf';
    private readonly CertificatePdfAssets $assets;

    public function __construct(
        private readonly CertificateElementRegistry $elements,
        private readonly TranslatorPort $translator,
        private readonly PdfPort $pdf,
        ?StoragePort $storage = null,
        private readonly CertificateLayout $layout = new CertificateLayout(),
        private readonly QrEncoder $qr = new QrEncoder(),
    ) {
        $this->assets = new CertificatePdfAssets($storage);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.certificate_renderer.pdf');
    }

    public function supportedTemplateFormats(): array
    {
        return ['elements'];
    }

    public function renderProvenance(): array
    {
        return ['renderer' => self::KEY, 'source_grammar' => 1] + $this->pdf->provenance();
    }

    public function prepareSource(array $template, array $pages): array
    {
        $this->assertSource($template, $pages);

        return ['render_provenance' => $this->renderProvenance(), 'render_assets' => $this->assets->freeze($pages)];
    }

    public function sourceFindings(array $template, array $pages): array
    {
        $findings = [];

        if (($template['format'] ?? '') !== 'elements' || $pages === [] || \count($pages) > 8) {
            $findings[] = $this->finding('source');
        }

        foreach ($pages as $page) {
            if (
                $page->width < 50 || $page->height < 50 || $page->width > 420 || $page->height > 420
                || !is_finite($page->width) || !is_finite($page->height)
                || \count($page->elements) > 100
            ) {
                $findings[] = $this->finding('paper');
            }

            foreach ($page->elements as $element) {
                $provider = $this->elements->has($element->element) ? $this->elements->get($element->element) : null;
                $supported = $provider instanceof SupportsPayloadVersions
                    ? $provider->supportedPayloadVersions() : [$provider?->currentShape()];

                if (
                    $provider === null || !\in_array($element->dataVersion, $supported, true)
                    || (!$provider instanceof ResolvesToText
                        && !\in_array($element->element, [
                            'border', 'colour_panel', 'qr_code', ...CertificatePdfAssets::IMAGE_ELEMENTS,
                        ], true))
                ) {
                    $findings[] = $this->finding('element', $element->id);
                    continue;
                }

                $box = $this->layout->boxFor($page, $element);

                if (
                    $element->element !== 'border' && ($box->left < 0 || $box->top < 0
                    || $box->left + ($box->width ?? 0) > $page->width
                    || $box->top + ($box->height ?? 0) > $page->height)
                ) {
                    $findings[] = $this->finding('bounds', $element->id);
                }

                try {
                    $this->assertStyle($page, $element);
                } catch (ValidationException) {
                    $findings[] = $this->finding('style', $element->id);
                }
            }
        }

        foreach (CertificatePdfAssets::paths($pages) as $path) {
            if (!CertificatePdfAssets::safePath($path)) {
                $findings[] = $this->finding('asset');
            }
        }

        return $findings;
    }

    public function render(CertificateRenderContext $context): string
    {
        return $this->renderBinary($context)->bytes;
    }

    public function renderBinary(CertificateRenderContext $context): RenderedArtefact
    {
        $this->assertSource($context->template, $context->pages);
        $source = $context->fromSnapshot && !$context->isPreview()
            ? $context->template : $this->prepareSource($context->template, $context->pages);

        if (
            self::runtimeContract($source['render_provenance'] ?? null)
            !== self::runtimeContract($this->renderProvenance())
        ) {
            throw ConflictException::with('The frozen PDF renderer document format is not supported by this runtime.', [
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'renderer' => self::KEY,
            ]);
        }

        $assets = $source['render_assets'] ?? [];

        if (!\is_array($assets)) {
            throw ValidationException::forField('assets', 'The frozen PDF asset document is invalid.');
        }

        $frozen = [];

        foreach ($assets as $path => $asset) {
            if (
                !\is_string($path) || !\is_array($asset) || !\is_string($asset['base64'] ?? null)
                || !\is_string($asset['sha256'] ?? null) || !\is_string($asset['media_type'] ?? null)
            ) {
                throw ValidationException::forField('assets', 'The frozen PDF asset document is invalid.');
            }
            $frozen[$path] = ['base64' => $asset['base64'], 'sha256' => $asset['sha256'], 'media_type' => $asset['media_type']];
        }

        $pages = $context->pages;
        usort($pages, static fn (CertificateTemplatePage $a, CertificateTemplatePage $b): int
            => [$a->sequence, $a->id] <=> [$b->sequence, $b->id]);
        $operations = array_map(fn (CertificateTemplatePage $page): array => $this->page($page, $context), $pages);
        $code = $context->token('certificate.code_display') ?? $context->token('certificate.code') ?? 'certificate';
        $filename = preg_replace('/[^A-Za-z0-9_-]/', '-', $code) ?: 'certificate';

        return RenderedArtefact::of($this->pdf->render(new PdfDocument(
            (float) $pages[0]->width,
            (float) $pages[0]->height,
            $operations,
            $frozen,
            $context->isPreview() ? 'Certificate preview' : 'Certificate ' . $code,
            $context->now->format('Y-m-d\TH:i:sP'),
            $context->isPreview(),
            array_map(static fn (CertificateTemplatePage $page): array => [
                'width' => $page->width, 'height' => $page->height,
            ], $pages),
        )), 'application/pdf', ($context->isPreview() ? 'certificate-preview' : substr($filename, 0, 100)) . '.pdf');
    }

    /**
     * Hashes and dependency versions remain in the snapshot as audit evidence.
     * Compatibility follows the explicit drawing/source grammar, so installing
     * a security patch does not require republishing every certificate template.
     *
     * @return array<string, mixed>|null
     */
    private static function runtimeContract(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        $contract = [];
        foreach (['renderer', 'source_grammar', 'engine', 'worker_grammar', 'font_policy'] as $key) {
            if (array_key_exists($key, $value)) {
                $contract[$key] = $value[$key];
            }
        }
        return $contract;
    }

    /** @return list<array<string, mixed>> */
    private function page(CertificateTemplatePage $page, CertificateRenderContext $context): array
    {
        $ops = [];

        if ($page->background !== null && $page->background !== '') {
            $ops[] = ['op' => 'image', 'asset' => $page->background, 'x' => 0, 'y' => 0, 'w' => $page->width, 'h' => $page->height];
        }

        $elements = $page->elements;
        usort($elements, static fn (CertificateTemplateElement $a, CertificateTemplateElement $b): int
            => [$a->sequence, $a->id] <=> [$b->sequence, $b->id]);

        foreach ($elements as $element) {
            $box = $this->layout->boxFor($page, $element);
            $payload = $element->data;
            $elementContext = ElementRenderContext::create(
                $page,
                $box,
                $context->now,
                $context->stringTokens(),
                $context->locale,
                $context->timezone,
                $context->isPreview() ? ElementRenderContext::PURPOSE_PREVIEW : ElementRenderContext::PURPOSE_ISSUE,
            );
            $base = ['x' => $box->left, 'y' => $box->top, 'w' => $box->width ?? 1, 'h' => $box->height ?? ($page->height - $box->top)];
            $provider = $this->elements->get($element->element);

            if ($element->element === 'border') {
                $inset = self::number($payload['inset_mm'] ?? 8, 0, 100);
                $ops[] = ['op' => 'border', 'x' => $inset, 'y' => $inset,
                    'w' => $page->width - 2 * $inset, 'h' => $page->height - 2 * $inset,
                    'colour' => self::colour($payload['colour'] ?? '#20344b'),
                    'weight' => self::number($payload['width_pt'] ?? 1, 0.1, 20),
                    'style' => $payload['style'] ?? 'solid',
                ];
            } elseif ($element->element === 'colour_panel') {
                if (($payload['rule_width_pt'] ?? null) !== null) {
                    $base['h'] = min($base['h'], self::number($payload['rule_width_pt'], 1, 20) * 25.4 / 72);
                }
                $ops[] = ['op' => 'fill', 'colour' => self::colour($payload['colour'] ?? '#202c3b')] + $base;
            } elseif ($element->element === 'qr_code') {
                $token = \is_string($payload['token'] ?? null) ? $payload['token'] : 'certificate.verification_url';
                $value = $context->token($token) ?? '';

                if ($value === '') {
                    throw ValidationException::forField('qr_code', 'The QR token has no value.');
                }
                try {
                    $matrix = $this->qr->encode($value);
                } catch (\InvalidArgumentException) {
                    throw ValidationException::forField('qr_code', 'The QR value exceeds the supported capacity.');
                }
                $size = self::number($payload['size_mm'] ?? 28, 10, 100);
                $ops[] = ['op' => 'qr', 'matrix' => $matrix, 'size' => $size] + $base;
            } elseif (\in_array($element->element, CertificatePdfAssets::IMAGE_ELEMENTS, true)) {
                $ops[] = ['op' => 'image', 'asset' => $payload['path'] ?? ''] + $base;
            } elseif ($provider instanceof ResolvesToText) {
                $font = \is_array($payload['font'] ?? null) ? $payload['font'] : [];
                $family = \is_string($font['family'] ?? null) ? strtolower($font['family']) : '';
                $text = $provider->resolveText($payload, $elementContext) ?? '';

                if ($provider instanceof TokenTextElement && \is_string($payload['label'] ?? null) && $payload['label'] !== '') {
                    $text = $elementContext->resolveText($payload['label']) . ' ' . $text;
                }
                if ($element->element === 'verification_code' && ($payload['show_url'] ?? true) !== false) {
                    $text .= ' ' . ($context->token('certificate.verification_url') ?? '');
                }

                $ops[] = ['op' => 'text', 'text' => $text,
                    'family' => str_contains($family, 'georgia') || str_contains($family, 'times') ? 'serif' : 'bricolage',
                    'size' => self::number($font['size'] ?? 16, 4, 100),
                    'weight' => self::number($font['weight'] ?? 400, 100, 900) >= 600 ? 700 : 400,
                    'colour' => self::colour($payload['colour'] ?? '#20344b'),
                    'line_height' => self::number($payload['line_height'] ?? 1.4, 0.8, 3),
                    'align' => $box->textAlign,
                ] + $base;
            }
        }

        return $ops;
    }

    private function assertStyle(CertificateTemplatePage $page, CertificateTemplateElement $element): void
    {
        $payload = $element->data;

        if (\in_array($element->element, CertificatePdfAssets::IMAGE_ELEMENTS, true)) {
            if (!\is_string($payload['path'] ?? null) || !CertificatePdfAssets::safePath($payload['path'])) {
                throw ValidationException::forField('assets', 'Select a local image asset.');
            }
            return;
        }
        if ($element->element === 'qr_code') {
            $size = self::number($payload['size_mm'] ?? 28, 10, 100);
            $box = $this->layout->boxFor($page, $element);

            if ($size > ($box->width ?? 1) || $size > ($box->height ?? ($page->height - $box->top))) {
                throw ValidationException::forField('qr_code', 'Enlarge the QR element to contain its declared size.');
            }
            return;
        }
        self::colour($payload['colour'] ?? '#20344b');

        if ($element->element === 'colour_panel') {
            if (($payload['rule_width_pt'] ?? null) !== null) {
                self::number($payload['rule_width_pt'], 1, 20);
            }
            return;
        }

        if ($element->element === 'border') {
            self::number($payload['inset_mm'] ?? 8, 0, min(100, min($page->width, $page->height) / 2 - 1));
            self::number($payload['width_pt'] ?? 1, 0.1, 20);

            if (($payload['radius_mm'] ?? 0) !== 0) {
                throw ValidationException::forField('radius_mm', 'This PDF renderer supports square certificate borders.');
            }

            if (!\in_array($payload['style'] ?? 'solid', ['solid', 'double', 'dashed', 'dotted'], true)) {
                throw ValidationException::forField('style', 'Select a supported PDF border style.');
            }
            return;
        }
        $font = \is_array($payload['font'] ?? null) ? $payload['font'] : [];
        self::number($font['size'] ?? 16, 4, 100);
        self::number($font['weight'] ?? 400, 100, 900);
        self::number($payload['line_height'] ?? 1.4, 0.8, 3);
    }

    /**
     * @param array<string, mixed> $template
     * @param list<CertificateTemplatePage> $pages
     */
    private function assertSource(array $template, array $pages): void
    {
        foreach ($this->sourceFindings($template, $pages) as $finding) {
            if ($finding->blocks()) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField('renderer', $this->translator->translate($finding->messageKey, $finding->parameters));
            }
        }
    }

    private function finding(string $code, ?int $element = null): ValidationFinding
    {
        return ValidationFinding::error('pdf.' . $code, 'vidyalms.certificate_pdf.unsupported', [
            'reason' => $this->translator->translate('vidyalms.certificate_pdf.reason.' . $code), 'element_id' => $element,
        ]);
    }

    private static function number(mixed $value, float $minimum, float $maximum): float
    {
        if ((!\is_int($value) && !\is_float($value)) || !is_finite((float) $value) || $value < $minimum || $value > $maximum) {
            throw ValidationException::forField('renderer', 'A PDF numeric style is outside its supported range.');
        }

        return (float) $value;
    }

    private static function colour(mixed $value): string
    {
        if (!\is_string($value) || preg_match('/^#[0-9a-f]{6}$/iD', $value) !== 1) {
            throw ValidationException::forField('colour', 'PDF colours must use six-digit hexadecimal notation.');
        }

        return $value;
    }
}
