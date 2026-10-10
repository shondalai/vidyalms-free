<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ProvisioningSource;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<ProvisioningSourceInterface>
 */
final class ProvisioningSourceRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return ProvisioningSourceInterface::class;
    }
}
