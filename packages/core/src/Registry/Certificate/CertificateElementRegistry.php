<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<CertificateElementInterface>
 */
final class CertificateElementRegistry extends AbstractProviderRegistry
{
    /**
     * Every optional behaviour an element may declare, and the name the
     * palette publishes it under.
     *
     * It is a declared map rather than a chain of instanceof branches inside
     * the palette handler, because that handler is the one surface a third
     * party reads to discover what an element can do, and a closed list there
     * meant a capability added later could not reach the palette without a
     * core edit. Every other list that handler returns is registry-driven.
     *
     * @var array<class-string, string>
     */
    public const CAPABILITIES = [
        ResolvesToText::class => 'resolves_to_text',
        RequiresAsset::class => 'requires_asset',
        ContributesToCredential::class => 'contributes_to_credential',
        ProvidesCredentialValue::class => 'provides_credential_value',
        ProvidesReadableVerification::class => 'provides_readable_verification',
        RendersToPdf::class => 'renders_to_pdf',
    ];

    protected function providerInterface(): string
    {
        return CertificateElementInterface::class;
    }
}
