<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\EnrolmentRule;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<EnrolmentRuleInterface>
 */
final class EnrolmentRuleRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return EnrolmentRuleInterface::class;
    }
}
