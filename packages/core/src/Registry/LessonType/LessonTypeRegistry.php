<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\LessonType;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<LessonTypeInterface>
 */
final class LessonTypeRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return LessonTypeInterface::class;
    }
}
