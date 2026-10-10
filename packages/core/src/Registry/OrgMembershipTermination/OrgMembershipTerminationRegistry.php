<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgMembershipTermination;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<OrgMembershipTerminationPolicyInterface>
 */
final class OrgMembershipTerminationRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return OrgMembershipTerminationPolicyInterface::class;
    }
}
