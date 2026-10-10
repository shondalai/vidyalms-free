<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate\Element;

use Shondalai\VidyaLMS\Core\Registry\Certificate\ContributesToCredential;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ElementMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ProvidesReadableVerification;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;

/**
 * The code a person retypes, and optionally the URL they type it into.
 *
 * The code is drawn grouped for reading and stored flat, so a site that later
 * sets a display prefix changes nothing already printed. The URL is real
 * selectable text and not only a picture, which is the accessibility rule the
 * whole area turns on: a QR code is a convenience and never the only route.
 */
final class VerificationCodeElement extends TokenTextElement implements
    ContributesToCredential,
    ProvidesReadableVerification
{
    public function key(): string
    {
        return 'verification_code';
    }

    /**
     * Only when the URL is actually drawn. show_url false suppresses exactly
     * the selectable address the template-level rule exists to require, so
     * this element answers its own flag rather than having core read it.
     */
    public function emitsVerificationUrl(array $payload): bool
    {
        return ($payload['show_url'] ?? true) !== false;
    }

    public function group(): string
    {
        return ElementMetadataInterface::GROUP_VERIFICATION;
    }

    public function order(): int
    {
        return 10;
    }

    public function credentialProperty(): string
    {
        return 'id';
    }

    protected function tokenName(): string
    {
        return 'certificate.code_display';
    }

    protected function defaultSize(): int
    {
        return 12;
    }

    public function defaultPayload(): array
    {
        return parent::defaultPayload() + ['show_url' => true];
    }

    public function formSchema(): array
    {
        $schema = parent::formSchema();
        $schema['fields']['show_url'] = [
            'type' => 'boolean',
            'label' => 'vidyalms.certificate_element.field.show_url',
        ];

        return $schema;
    }

    public function renderHtml(array $payload, ElementRenderContext $context): string
    {
        $code = $this->resolveText($payload, $context) ?? $context->token('certificate.code');
        $showUrl = ($payload['show_url'] ?? true) !== false;
        $url = $showUrl ? $context->token('certificate.verification_url') : null;

        $inner = '<span class="vy-cert-code">' . $this->escape($code ?? '') . '</span>';

        if ($url !== null && $url !== '') {
            $inner .= ' <a class="vy-cert-verify-url" style="color: inherit;" href="' . $this->escape($url) . '">'
                . $this->escape($url)
                . '</a>';
        }

        return $this->block($context, $inner, $this->typographyStyle($payload));
    }
}
