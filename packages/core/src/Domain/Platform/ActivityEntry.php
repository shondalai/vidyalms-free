<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

/**
 * One line in the activity log: who did what, where, and when.
 */
final class ActivityEntry
{
    /**
     * @param array<string, mixed>|null $context
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $action,
        public readonly \DateTimeImmutable $occurredAt,
        public readonly ?int $courseId = null,
        public readonly ?int $lessonId = null,
        public readonly ?int $enrolmentId = null,
        public readonly ?array $context = null,
        public readonly ?string $eventRef = null,
    ) {
    }
}
