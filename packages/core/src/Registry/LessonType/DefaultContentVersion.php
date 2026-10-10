<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\LessonType;

/**
 * The version half of LessonTypeInterface, for a provider whose content shape
 * has never changed. See DefaultDefinitionVersion for why this is a trait.
 */
trait DefaultContentVersion
{
    public function contentVersion(): int
    {
        return 1;
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return array<string, mixed>
     */
    public function upgradeContent(array $content, int $fromVersion): array
    {
        return $content;
    }
}
