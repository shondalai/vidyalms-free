<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\SeatOverAllocation;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<SeatOverAllocationInterface>
 */
final class SeatOverAllocationRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return SeatOverAllocationInterface::class;
    }
}
