<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgCatalogueSource;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<OrgCatalogueSourceInterface>
 */
final class OrgCatalogueSourceRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return OrgCatalogueSourceInterface::class;
    }
}
