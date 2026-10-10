<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

/**
 * The latest grade a human gave one item. Grade history is append-only, so
 * this is the newest row, never the only one.
 */
final class ManualGrade
{
    public function __construct(
        public readonly float $pointsAwarded,
        public readonly float $maxPoints,
        public readonly ?string $feedback,
        public readonly \DateTimeImmutable $gradedAt,
        public readonly int $graderId,
    ) {
    }
}
