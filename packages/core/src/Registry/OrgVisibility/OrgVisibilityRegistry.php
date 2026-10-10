<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgVisibility;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<OrgVisibilityPolicyInterface>
 */
final class OrgVisibilityRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return OrgVisibilityPolicyInterface::class;
    }
}
