<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<AiRecipeInterface>
 */
final class AiRecipeRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return AiRecipeInterface::class;
    }
}
