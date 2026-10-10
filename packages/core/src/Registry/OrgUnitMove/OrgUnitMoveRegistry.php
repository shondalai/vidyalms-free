<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgUnitMove;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<OrgUnitMovePolicyInterface>
 */
final class OrgUnitMoveRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return OrgUnitMovePolicyInterface::class;
    }
}
