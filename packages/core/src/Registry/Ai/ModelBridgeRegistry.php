<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<ModelBridgeInterface>
 */
final class ModelBridgeRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return ModelBridgeInterface::class;
    }
}
