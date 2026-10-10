<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

/**
 * One attempt whose total moved, before and after.
 */
final class RegradeAttemptChange
{
    public function __construct(
        public readonly int $attemptId,
        public readonly int $userId,
        public readonly int $attemptNumber,
        public readonly ?float $scoreBefore,
        public readonly ?float $scoreAfter,
        public readonly ?float $percentageBefore,
        public readonly ?float $percentageAfter,
        public readonly ?bool $passedBefore,
        public readonly ?bool $passedAfter,
        public readonly int $questionsChanged,
    ) {
    }
}
