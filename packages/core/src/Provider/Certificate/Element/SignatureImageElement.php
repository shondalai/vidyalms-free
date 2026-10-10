<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;

/**
 * A signature, with the signatory named underneath.
 *
 * It is its own element rather than an image with a caption because NASBA
 * names the signature of the person responsible for continuing-education
 * administration as one of eleven required elements, and a compliance
 * requirement that is expressed as "use the image element and remember to add
 * a text element under it" is a requirement a site will get wrong.
 */
final class SignatureImageElement extends ImageElement
{
    public function key(): string
    {
        return 'signature_image';
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_IDENTITY;
    }

    public function order(): int
    {
        return 50;
    }

    public function defaultPayload(): array
    {
        return parent::defaultPayload() + ['signatory' => '', 'role' => ''];
    }

    public function formSchema(): array
    {
        $schema = parent::formSchema();
        $schema['fields']['signatory'] = [
            'type' => 'string',
            'label' => 'vidyalms.certificate_element.field.signatory',
        ];
        $schema['fields']['role'] = [
            'type' => 'string',
            'label' => 'vidyalms.certificate_element.field.signatory_role',
        ];

        return $schema;
    }

    public function renderHtml(array $payload, ElementRenderContext $context): string
    {
        $image = parent::renderHtml($payload, $context);
        $signatory = $payload['signatory'] ?? null;
        $role = $payload['role'] ?? null;

        if (!\is_string($signatory) || $signatory === '') {
            return $image;
        }

        $caption = '<div class="vy-cert-signatory">'
            . $this->escape($context->resolveText($signatory))
            . (\is_string($role) && $role !== ''
                ? '<br><span class="vy-cert-signatory-role">' . $this->escape($context->resolveText($role)) . '</span>'
                : '')
            . '</div>';

        // The caption belongs inside the same positioned block, so the layout
        // service still owns the position and the element still owns nothing
        // but its own content.
        return substr($image, 0, -6) . $caption . '</div>';
    }
}
