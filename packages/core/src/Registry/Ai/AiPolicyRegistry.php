<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<AiPolicyInterface>
 */
final class AiPolicyRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return AiPolicyInterface::class;
    }
}
