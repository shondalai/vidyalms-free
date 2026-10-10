<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\SeatScope;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<SeatScopeInterface>
 */
final class SeatScopeRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return SeatScopeInterface::class;
    }
}
