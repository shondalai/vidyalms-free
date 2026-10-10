<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\SeatExpiryDisposition;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<SeatExpiryDispositionInterface>
 */
final class SeatExpiryDispositionRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return SeatExpiryDispositionInterface::class;
    }
}
