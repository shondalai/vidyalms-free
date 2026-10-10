<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Notification;

/**
 * One instant a rule's schedule produces, with the occurrence key that makes
 * it distinct from its siblings in the dedupe digest.
 *
 * The key is what stops a recurring window bounded at eleven occurrences
 * enqueuing the first and silently dropping ten: the other six digest
 * components are identical across every occurrence of a repeating schedule,
 * so without the ordinal the second insert is read as a replay of the first.
 */
final class ScheduledOccurrence
{
    private function __construct(
        public readonly \DateTimeImmutable $runAt,
        public readonly string $occurrenceKey,
    ) {
    }

    public static function at(\DateTimeImmutable $runAt, string $occurrenceKey): self
    {
        return new self($runAt, $occurrenceKey);
    }

    /**
     * The single-shot occurrence, which keeps whatever key the event minted
     * rather than inventing one of its own.
     */
    public static function once(\DateTimeImmutable $runAt, string $occurrenceKey): self
    {
        return new self($runAt, $occurrenceKey);
    }

    /**
     * The nth occurrence of a recurring window, ordinal from 1, suffixed onto
     * the event's own key so two events under one rule never collide.
     */
    public static function ordinal(\DateTimeImmutable $runAt, string $baseKey, int $ordinal): self
    {
        return new self($runAt, $baseKey . '#' . $ordinal);
    }
}
