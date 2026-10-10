<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\IdentityMapper;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<IdentityMapperInterface>
 */
final class IdentityMapperRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return IdentityMapperInterface::class;
    }
}
