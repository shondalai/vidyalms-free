<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<AiFactProviderInterface>
 */
final class AiFactProviderRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return AiFactProviderInterface::class;
    }
}
