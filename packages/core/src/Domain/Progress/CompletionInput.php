<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Progress;

/**
 * Everything the completion engine needs to decide, captured as plain values
 * so the decision is a pure function over progress.
 */
final class CompletionInput
{
    public function __construct(
        public readonly int $lessonsTotal,
        public readonly int $lessonsCompleted,
        public readonly string $rule,
        public readonly ?int $threshold = null,
        public readonly ?bool $finalQuizPassed = null,
    ) {
    }
}
