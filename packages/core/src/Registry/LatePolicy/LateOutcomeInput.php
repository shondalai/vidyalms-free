<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\LatePolicy;

/**
 * What a late policy is handed: the score before any penalty, the deadline it
 * was due by, and the instant it actually arrived.
 *
 * A value object rather than positional members, so a later field is
 * additive and no registered policy breaks.
 */
final class LateOutcomeInput
{
    public function __construct(
        public readonly float $rawPercentage,
        public readonly ?\DateTimeImmutable $dueAt,
        public readonly \DateTimeImmutable $submittedAt,
    ) {
    }

    public function lateSeconds(): int
    {
        if ($this->dueAt === null) {
            return 0;
        }

        return max(0, $this->submittedAt->getTimestamp() - $this->dueAt->getTimestamp());
    }

    public function isLate(): bool
    {
        return $this->lateSeconds() > 0;
    }
}
