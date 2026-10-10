<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<ImporterInterface>
 */
final class ImporterRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return ImporterInterface::class;
    }
}
