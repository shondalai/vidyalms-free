<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ResolvesToText;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;

/**
 * An element that draws exactly one resolved token, with an optional label
 * above it.
 *
 * Eight of the seventeen are this, and they are one class rather than eight
 * copies for the same reason the layout service exists: eight copies of "read
 * a token, escape it, wrap it in a positioned block" is eight chances to
 * escape it differently.
 *
 * Every one of them resolves to text, so a text element can reuse the same
 * value through a placeholder and the credential builder can read it without
 * parsing markup.
 */
abstract class TokenTextElement extends AbstractCertificateElement implements ResolvesToText
{
    /**
     * The placeholder this element draws, without braces.
     */
    abstract protected function tokenName(): string;

    protected function defaultSize(): int
    {
        return 18;
    }

    protected function defaultWeight(): int
    {
        return 400;
    }

    public function defaultPayload(): array
    {
        return $this->typographyDefaults($this->defaultSize(), $this->defaultWeight()) + [
            'label' => null,
            'fallback' => '',
        ];
    }

    public function formSchema(): array
    {
        return [
            'fields' => $this->typographySchema() + [
                'label' => [
                    'type' => 'string',
                    'label' => 'vidyalms.certificate_element.field.label',
                    'nullable' => true,
                ],
                'fallback' => ['type' => 'string', 'label' => 'vidyalms.certificate_element.field.fallback'],
            ],
        ];
    }

    public function resolveText(array $payload, ElementRenderContext $context): ?string
    {
        $value = $context->token($this->tokenName());

        if ($value !== null && $value !== '') {
            return $value;
        }

        $fallback = $payload['fallback'] ?? null;

        return \is_string($fallback) && $fallback !== '' ? $fallback : null;
    }

    public function renderHtml(array $payload, ElementRenderContext $context): string
    {
        $value = $this->resolveText($payload, $context);
        $label = $payload['label'] ?? null;
        $inner = '';

        if (\is_string($label) && $label !== '') {
            $inner .= '<span class="vy-cert-label">' . $this->escape($context->resolveText($label)) . '</span> ';
        }

        // An unresolved value leaves the placeholder visible rather than a
        // blank line, which is the published miss direction for a token: a
        // visible placeholder is a bug report and a blank is a silent one.
        $inner .= '<span class="vy-cert-value">'
            . $this->escape($value ?? '{' . $this->tokenName() . '}')
            . '</span>';

        return $this->block($context, $inner, $this->typographyStyle($payload));
    }
}
