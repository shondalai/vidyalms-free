<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\SeatEventReason;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<SeatEventReasonInterface>
 */
final class SeatEventReasonRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return SeatEventReasonInterface::class;
    }
}
