<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Credit;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<CreditSchemeInterface>
 */
final class CreditSchemeRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return CreditSchemeInterface::class;
    }
}
