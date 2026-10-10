<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Application\Credentials\Qr\QrEncoder;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RendersToPdf;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementValidationContext;

/**
 * The verification URL as a scannable code, and never as the only route to
 * it.
 *
 * The alt text is required for the same reason the image elements require
 * one, and the rule that a template needs readable verification text is
 * enforced at the template by a publication check rather than hoped for here:
 * validate() is per element and cannot see whether a sibling exists, so a
 * template holding this element and nothing else would otherwise pass every
 * check as specified while shipping a certificate no screen reader can
 * verify.
 *
 * It declares the PDF capability because rasterising a QR code from HTML is
 * how a code becomes unscannable at print resolution.
 */
final class QrCodeElement extends AbstractCertificateElement implements RendersToPdf
{
    public function __construct(private readonly QrEncoder $encoder = new QrEncoder())
    {
    }

    public function key(): string
    {
        return 'qr_code';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_VERIFICATION;
    }

    public function order(): int
    {
        return 20;
    }

    public function defaultPayload(): array
    {
        return [
            'token' => 'certificate.verification_url',
            'alt' => '',
            'size_mm' => 28,
        ];
    }

    public function formSchema(): array
    {
        return [
            'fields' => [
                'token' => ['type' => 'string', 'label' => 'vidyalms.certificate_element.field.token_field'],
                'alt' => ['type' => 'string', 'required' => true, 'label' => 'vidyalms.certificate_element.field.alt'],
                'size_mm' => ['type' => 'integer', 'label' => 'vidyalms.certificate_element.field.size_mm'],
            ],
        ];
    }

    public function validate(array $payload, ElementValidationContext $context): array
    {
        $alt = $payload['alt'] ?? null;

        return \is_string($alt) && trim($alt) !== '' ? [] : [$this->missing('alt')];
    }

    public function renderHtml(array $payload, ElementRenderContext $context): string
    {
        $value = $this->payloadValue($payload, $context);

        if ($value === null) {
            return $this->absent($context, 'no_value');
        }

        $alt = \is_string($payload['alt'] ?? null) ? $payload['alt'] : '';

        try {
            $svg = $this->encoder->toSvg($value, $this->sizePx($payload), $alt);
        } catch (\InvalidArgumentException) {
            // A payload the encoder refuses is an authoring problem, not a
            // render failure: the certificate still prints, and the URL is
            // selectable text elsewhere on the page by rule.
            return $this->absent($context, 'unencodable');
        }

        $inner = '<span role="img" aria-label="' . $this->escape($alt) . '">' . $svg . '</span>';

        return $this->block($context, $inner);
    }

    public function pdfInstructions(array $payload, ElementRenderContext $context): array
    {
        $value = $this->payloadValue($payload, $context);

        if ($value === null) {
            return [];
        }

        try {
            $matrix = $this->encoder->encode($value);
        } catch (\InvalidArgumentException) {
            return [];
        }

        $modules = \count($matrix);
        $size = $this->sizeMm($payload);
        $module = $size / ($modules + 8);
        $instructions = [];

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $instructions[] = [
                        'op' => 'rect',
                        'x' => $context->box->left + (($x + 4) * $module),
                        'y' => $context->box->top + (($y + 4) * $module),
                        'width' => $module,
                        'height' => $module,
                        'fill' => '#000000',
                    ];
                }
            }
        }

        return $instructions;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function payloadValue(array $payload, ElementRenderContext $context): ?string
    {
        $token = $payload['token'] ?? null;
        $token = \is_string($token) && $token !== '' ? $token : 'certificate.verification_url';
        $value = $context->token($token);

        return $value !== null && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sizeMm(array $payload): float
    {
        $size = $payload['size_mm'] ?? null;

        return \is_int($size) && $size > 0 ? (float) $size : 28.0;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sizePx(array $payload): int
    {
        // Roughly 96 dots to the inch, which is what a browser assumes for a
        // millimetre and what keeps the SVG viewport square with the box.
        return (int) round($this->sizeMm($payload) * 96 / 25.4);
    }
}
