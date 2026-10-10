<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Curriculum;

/**
 * The counts a course list and a workbench header show. Every one of them is
 * computed on read: nothing is cached on the course row, so a count is never
 * stale and never needs a repair job.
 */
final class CourseCounts
{
    public function __construct(
        public readonly int $lessons,
        public readonly int $archivedLessons,
        public readonly int $enrolled,
        public readonly int $active,
        public readonly int $completed,
        public readonly int $suspended,
    ) {
    }

    public static function empty(): self
    {
        return new self(0, 0, 0, 0, 0, 0);
    }

    /**
     * Completed over enrolled as a percentage, or null when nobody is
     * enrolled: zero would claim a failure rate the course has not earned.
     */
    public function completionRate(): ?float
    {
        if ($this->enrolled === 0) {
            return null;
        }

        return round($this->completed / $this->enrolled * 100, 2);
    }
}
