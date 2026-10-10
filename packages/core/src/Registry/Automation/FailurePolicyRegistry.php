<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<FailurePolicyInterface>
 */
final class FailurePolicyRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return FailurePolicyInterface::class;
    }
}
