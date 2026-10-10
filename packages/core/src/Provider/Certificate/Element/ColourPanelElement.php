<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ValidationFinding;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementValidationContext;

/** Editable page fills, accent bands and rules, drawn in the element's own box. */
final class ColourPanelElement extends AbstractCertificateElement
{
    public function key(): string
    {
        return 'colour_panel';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_DECORATION;
    }

    public function order(): int
    {
        return 20;
    }

    public function defaultPayload(): array
    {
        return ['colour' => '#202c3b', 'rule_width_pt' => null];
    }

    public function formSchema(): array
    {
        return ['fields' => [
            'colour' => ['type' => 'string', 'label' => 'vidyalms.certificate_element.field.colour'],
            'rule_width_pt' => ['type' => 'integer', 'nullable' => true, 'minimum' => 1, 'maximum' => 20,
                'label' => 'vidyalms.certificate_element.field.rule_width'],
        ]];
    }

    public function validate(array $payload, ElementValidationContext $context): array
    {
        $width = $payload['rule_width_pt'] ?? null;
        return self::validColour($payload['colour'] ?? null)
            && ($width === null || (\is_int($width) && $width >= 1 && $width <= 20)) ? [] : [ValidationFinding::error(
                'colour_panel.invalid_colour',
                'vidyalms.certificate_element.error.panel_colour',
            )];
    }

    public function renderHtml(array $payload, ElementRenderContext $context): string
    {
        $colour = self::validColour($payload['colour'] ?? null) ? (string) $payload['colour'] : '#202c3b';

        $rule = $payload['rule_width_pt'] ?? null;
        $height = \is_int($rule) ? ' height: ' . min($context->box->height ?? 210, max(1, min(20, $rule)) * 25.4 / 72) . 'mm;' : '';

        return '<div class="vy-cert-colour-panel" aria-hidden="true" style="'
            . $context->box->style() . $height . ' background-color: ' . $this->escape($colour) . ';"></div>';
    }

    private static function validColour(mixed $colour): bool
    {
        return \is_string($colour) && preg_match('/^#[0-9a-f]{6}$/iD', $colour) === 1;
    }
}
