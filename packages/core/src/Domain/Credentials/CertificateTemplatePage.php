<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * One sheet of paper in a template.
 *
 * Paper geometry is host-owned and read by the layout service for every
 * element on the page, which is why it is columns rather than payload: an
 * element implementation may never manage its own position, and a page whose
 * width lived inside a provider's JSON could not be laid out without asking a
 * provider for it.
 *
 * Millimetres, because that is what the renderers and every paper standard
 * agree on. A4 landscape is the default.
 */
final class CertificateTemplatePage
{
    /**
     * @param list<CertificateTemplateElement> $elements
     */
    public function __construct(
        public readonly int $id,
        public readonly int $templateId,
        public readonly int $sequence = 1,
        public readonly float $width = 297,
        public readonly float $height = 210,
        public readonly string $orientation = 'landscape',
        public readonly int $marginTop = 10,
        public readonly int $marginRight = 10,
        public readonly int $marginBottom = 10,
        public readonly int $marginLeft = 10,
        public readonly ?string $background = null,
        public readonly array $elements = [],
    ) {
    }

    /**
     * @param list<CertificateTemplateElement> $elements
     */
    public function withElements(array $elements): self
    {
        return new self(
            $this->id,
            $this->templateId,
            $this->sequence,
            $this->width,
            $this->height,
            $this->orientation,
            $this->marginTop,
            $this->marginRight,
            $this->marginBottom,
            $this->marginLeft,
            $this->background,
            $elements,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'id' => $this->id,
            'sequence' => $this->sequence,
            'width' => $this->width,
            'height' => $this->height,
            'orientation' => $this->orientation,
            'margin_top' => $this->marginTop,
            'margin_right' => $this->marginRight,
            'margin_bottom' => $this->marginBottom,
            'margin_left' => $this->marginLeft,
            'background' => $this->background,
            'elements' => array_map(
                static fn (CertificateTemplateElement $element): array => $element->toSnapshot(),
                $this->elements,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function fromSnapshot(array $snapshot): self
    {
        $elements = [];
        $stored = $snapshot['elements'] ?? [];

        if (\is_array($stored)) {
            foreach ($stored as $element) {
                if (\is_array($element)) {
                    /** @var array<string, mixed> $element */
                    $elements[] = CertificateTemplateElement::fromSnapshot($element);
                }
            }
        }

        return new self(
            (int) ($snapshot['id'] ?? 0),
            (int) ($snapshot['template_id'] ?? 0),
            (int) ($snapshot['sequence'] ?? 1),
            (float) ($snapshot['width'] ?? 297),
            (float) ($snapshot['height'] ?? 210),
            \is_string($snapshot['orientation'] ?? null) ? $snapshot['orientation'] : 'landscape',
            (int) ($snapshot['margin_top'] ?? 10),
            (int) ($snapshot['margin_right'] ?? 10),
            (int) ($snapshot['margin_bottom'] ?? 10),
            (int) ($snapshot['margin_left'] ?? 10),
            \is_string($snapshot['background'] ?? null) ? $snapshot['background'] : null,
            $elements,
        );
    }
}
