<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Report;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<ReportProviderInterface>
 */
final class ReportRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return ReportProviderInterface::class;
    }
}
