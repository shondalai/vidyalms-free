<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<AvailabilityConditionInterface>
 */
final class AvailabilityConditionRegistry extends AbstractProviderRegistry
{
    /** The extension point name the provider ledger records these under. */
    public const EXTENSION_POINT = 'availability_condition';

    protected function providerInterface(): string
    {
        return AvailabilityConditionInterface::class;
    }
}
