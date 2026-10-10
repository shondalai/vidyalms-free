<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ResolvesToText;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ValidationFinding;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementValidationContext;

/**
 * Any field the user token family answers, chosen by name at authoring time.
 *
 * This and its course twin are the escape hatches that stop a fixed catalogue
 * being a ceiling, which is exactly how Moodle userfield and coursefield earn
 * their place: without them, the seventeenth site that needs an employee
 * number needs an eighteenth element, and the answer to every such request is
 * a core release.
 *
 * Not final, and deliberately so: the course escape hatch differs from this
 * one in exactly one string, and a second copy of the same hundred lines
 * would drift the first time either was fixed.
 */
class UserFieldElement extends AbstractCertificateElement implements ResolvesToText
{
    protected const PREFIX = 'user';

    public function key(): string
    {
        return 'user_field';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_ESCAPE_HATCH;
    }

    public function order(): int
    {
        return 10;
    }

    public function defaultPayload(): array
    {
        return $this->typographyDefaults(14) + ['field' => '', 'label' => null, 'fallback' => ''];
    }

    public function formSchema(): array
    {
        return [
            'fields' => $this->typographySchema() + [
                'field' => [
                    'type' => 'string',
                    'label' => 'vidyalms.certificate_element.field.token_field',
                    'source' => 'tokens:' . static::PREFIX,
                ],
                'label' => [
                    'type' => 'string',
                    'nullable' => true,
                    'label' => 'vidyalms.certificate_element.field.label',
                ],
                'fallback' => ['type' => 'string', 'label' => 'vidyalms.certificate_element.field.fallback'],
            ],
        ];
    }

    public function validate(array $payload, ElementValidationContext $context): array
    {
        $field = $payload['field'] ?? null;

        if (!\is_string($field) || $field === '') {
            return [$this->missing('field')];
        }

        $token = $this->tokenFor($field);

        if ($context->unresolvedTokens('{' . $token . '}') !== []) {
            return [ValidationFinding::warning(
                static::PREFIX . '_field.unresolved_token',
                'vidyalms.certificate_element.warning.unresolved_token',
                ['token' => $token],
            )];
        }

        return [];
    }

    public function resolveText(array $payload, ElementRenderContext $context): ?string
    {
        $field = $payload['field'] ?? null;

        if (!\is_string($field) || $field === '') {
            return null;
        }

        $value = $context->token($this->tokenFor($field));

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

        $inner .= '<span class="vy-cert-value">' . $this->escape($value ?? '') . '</span>';

        return $this->block($context, $inner, $this->typographyStyle($payload));
    }

    /**
     * An author may write either the bare field or the full placeholder, and
     * both mean the same thing. Accepting one shape only is how a support
     * ticket gets written.
     */
    protected function tokenFor(string $field): string
    {
        return str_contains($field, '.') ? $field : static::PREFIX . '.' . $field;
    }
}
