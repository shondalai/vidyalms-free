<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<AutomationActionInterface>
 */
final class AutomationActionRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return AutomationActionInterface::class;
    }
}
