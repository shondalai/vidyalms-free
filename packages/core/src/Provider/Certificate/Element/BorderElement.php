<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;

/**
 * A frame. It reads nothing and resolves nothing, which is why it is the one
 * element in the catalogue with no capability at all: it is decoration, it is
 * marked decorative to assistive technology, and it never carries meaning.
 */
final class BorderElement extends AbstractCertificateElement
{
    public function key(): string
    {
        return 'border';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_DECORATION;
    }

    public function order(): int
    {
        return 10;
    }

    public function defaultPayload(): array
    {
        return [
            'style' => 'double',
            'width_pt' => 10,
            'colour' => self::DEFAULT_COLOUR,
            'inset_mm' => 8,
            'radius_mm' => 0,
        ];
    }

    public function formSchema(): array
    {
        return [
            'fields' => [
                'style' => [
                    'type' => 'string',
                    'enum' => ['solid', 'double', 'dashed', 'dotted'],
                    'label' => 'vidyalms.certificate_element.field.border_style',
                ],
                'width_pt' => ['type' => 'integer', 'label' => 'vidyalms.certificate_element.field.border_width'],
                'colour' => ['type' => 'string', 'label' => 'vidyalms.certificate_element.field.colour'],
                'inset_mm' => ['type' => 'integer', 'label' => 'vidyalms.certificate_element.field.inset_mm'],
                'radius_mm' => ['type' => 'integer', 'label' => 'vidyalms.certificate_element.field.radius_mm'],
            ],
        ];
    }

    public function renderHtml(array $payload, ElementRenderContext $context): string
    {
        $style = \is_string($payload['style'] ?? null) ? $payload['style'] : 'double';
        $width = \is_int($payload['width_pt'] ?? null) ? $payload['width_pt'] : 10;
        $colour = \is_string($payload['colour'] ?? null) ? $payload['colour'] : self::DEFAULT_COLOUR;
        $inset = \is_int($payload['inset_mm'] ?? null) ? $payload['inset_mm'] : 8;
        $radius = \is_int($payload['radius_mm'] ?? null) ? $payload['radius_mm'] : 0;

        return '<div class="vy-cert-border" aria-hidden="true" style="position: absolute;'
            . ' inset: ' . $inset . 'mm;'
            // Both the style and the colour are author-controlled payload
            // landing inside a double-quoted attribute. This element builds
            // its own declaration rather than calling the shared typography
            // helper, which is how the colour kept its escape while the
            // helper's callers gained one.
            . ' border: ' . $width . 'pt ' . $this->escape($style) . ' ' . $this->escape($colour) . ';'
            . ($radius > 0 ? ' border-radius: ' . $radius . 'mm;' : '')
            . '"></div>';
    }
}
