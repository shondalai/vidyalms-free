<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Scheduler;

/**
 * A unit of deferred work: drip releases, reminders, certificate rendering,
 * report generation, package extraction. The type key selects a registered
 * task handler; the payload is job-specific and JSON-safe.
 */
final class ScheduledTask
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload,
        public readonly \DateTimeImmutable $runAt,
        public readonly ?string $dedupeKey = null,
        public readonly ?string $groupKey = null,
        public readonly bool $oncePerKey = false,
    ) {
    }
}
