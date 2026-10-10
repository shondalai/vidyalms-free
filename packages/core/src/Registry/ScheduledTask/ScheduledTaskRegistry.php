<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ScheduledTask;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<ScheduledTaskHandlerInterface>
 */
final class ScheduledTaskRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return ScheduledTaskHandlerInterface::class;
    }
}
