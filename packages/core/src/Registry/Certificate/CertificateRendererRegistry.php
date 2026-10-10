<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<CertificateRendererInterface>
 */
final class CertificateRendererRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return CertificateRendererInterface::class;
    }
}
