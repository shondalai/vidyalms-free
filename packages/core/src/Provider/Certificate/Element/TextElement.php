<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ProvidesReadableVerification;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ResolvesToText;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ValidationFinding;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementValidationContext;

/**
 * Free text with placeholders, which is the element every other one is an
 * ergonomic shortcut for.
 *
 * The text is escaped on the way out and the placeholders are substituted
 * before escaping, so a token whose value contains markup cannot inject it.
 * There is deliberately no rich text here: an admin-editable body rendered
 * through a template engine on a shared-hosting CMS is a server-side template
 * injection surface, which is why the vocabulary is closed and resolved by
 * token providers. Rich text with autocomplete is a paid element with its own
 * sanitiser.
 */
final class TextElement extends AbstractCertificateElement implements
    ResolvesToText,
    ProvidesReadableVerification
{
    public function key(): string
    {
        return 'text';
    }

    /**
     * Only when the authored body names the verification URL token. Any text
     * element counting would make the accessibility rule satisfiable by a
     * line of prose about anything.
     */
    public function emitsVerificationUrl(array $payload): bool
    {
        $text = $payload['text'] ?? null;

        return \is_string($text) && str_contains($text, '{certificate.verification_url}');
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_CONTENT;
    }

    public function order(): int
    {
        return 10;
    }

    public function defaultPayload(): array
    {
        return $this->typographyDefaults(18, 600) + [
            'text' => '',
            'max_width_mm' => null,
        ];
    }

    public function formSchema(): array
    {
        return [
            'fields' => $this->typographySchema() + [
                'text' => [
                    'type' => 'string',
                    'multiline' => true,
                    'label' => 'vidyalms.certificate_element.field.text',
                ],
                'max_width_mm' => [
                    'type' => 'integer',
                    'nullable' => true,
                    'label' => 'vidyalms.certificate_element.field.max_width_mm',
                ],
            ],
        ];
    }

    public function validate(array $payload, ElementValidationContext $context): array
    {
        $text = $payload['text'] ?? null;

        if (!\is_string($text) || trim($text) === '') {
            return [$this->missing('text')];
        }

        $findings = [];

        foreach ($context->unresolvedTokens($text) as $token) {
            // A warning and never an error: the published miss direction for
            // a token is that it is left intact, so a template written for a
            // richer build degrades visibly rather than refusing to save.
            $findings[] = ValidationFinding::warning(
                'text.unresolved_token',
                'vidyalms.certificate_element.warning.unresolved_token',
                ['token' => $token],
            );
        }

        return $findings;
    }

    public function resolveText(array $payload, ElementRenderContext $context): ?string
    {
        $text = $payload['text'] ?? null;

        return \is_string($text) ? $context->resolveText($text) : null;
    }

    public function renderHtml(array $payload, ElementRenderContext $context): string
    {
        $text = $this->resolveText($payload, $context) ?? '';
        $style = $this->typographyStyle($payload);
        $maxWidth = $payload['max_width_mm'] ?? null;

        if (\is_int($maxWidth) && $maxWidth > 0) {
            $style .= ' max-width: ' . $maxWidth . 'mm;';
        }

        return $this->block($context, nl2br($this->escape($text), false), $style);
    }
}
