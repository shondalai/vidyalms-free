<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Progress;

/**
 * Where a learner left off in a lesson's video, keyed by user and lesson so
 * resuming works across enrolments and after re-enrolment.
 */
final class VideoPosition
{
    public function __construct(
        public readonly int $userId,
        public readonly int $lessonId,
        public readonly int $positionSeconds,
        public readonly ?int $durationSeconds,
    ) {
    }
}
