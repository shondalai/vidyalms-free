<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Credentials;

use Shondalai\VidyaLMS\Core\Provider\Certificate\Element\AbstractCertificateElement;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ProvidesReadableVerification;
use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;

/**
 * A registered element that emits the verification URL as selectable text
 * under a payload key core has never heard of, which is what the paid rich
 * text element does.
 */
final class VerifyingProbeElement extends AbstractCertificateElement implements ProvidesReadableVerification
{
    public const KEY = 'probe_verification_text';

    public function key(): string
    {
        return self::KEY;
    }

    public function defaultPayload(): array
    {
        return ['body' => ''];
    }

    public function formSchema(): array
    {
        return ['fields' => ['body' => ['type' => 'string']]];
    }

    public function emitsVerificationUrl(array $payload): bool
    {
        $body = $payload['body'] ?? null;

        return \is_string($body) && str_contains($body, '{certificate.verification_url}');
    }

    public function renderHtml(array $payload, ElementRenderContext $context): string
    {
        $body = $payload['body'] ?? '';

        return $this->block($context, $this->escape($context->resolveText(\is_string($body) ? $body : '')));
    }
}
