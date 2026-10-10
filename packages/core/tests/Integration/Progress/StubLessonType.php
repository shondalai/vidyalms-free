<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Progress;

use Shondalai\VidyaLMS\Core\Registry\LessonType\DefaultContentVersion;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;

final class StubLessonType implements LessonTypeInterface
{
    use DefaultContentVersion;

    public function __construct(
        private readonly string $key,
        private readonly string $completionMode,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return ucfirst($this->key);
    }

    public function completionMode(): string
    {
        return $this->completionMode;
    }

    public function validateContent(array $content): void
    {
    }

    public function presentForLearner(array $content, bool $hasAccess): array
    {
        return $content;
    }
}
