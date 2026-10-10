<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<RetryPolicyInterface>
 */
final class RetryPolicyRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return RetryPolicyInterface::class;
    }
}
