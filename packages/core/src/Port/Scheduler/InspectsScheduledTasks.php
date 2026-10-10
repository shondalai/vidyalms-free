<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Scheduler;

/** Optional host-owned durable queue inspection; never exposes task payloads or raw errors. */
interface InspectsScheduledTasks
{
    /** @return array<string, mixed> */
    public function queueOverview(): array;

    /** @return array<string, mixed> */
    public function taskPage(int $page, int $perPage, ?string $state = null, ?string $type = null): array;

    /** @return array<string, mixed>|null */
    public function taskRecord(int $id): ?array;

    /** @return array<string, mixed>|null */
    public function taskGroup(string $key): ?array;

    /** @return array<string, mixed>|null Most recently created retained group matching a trusted prefix. */
    public function latestTaskGroup(string $prefix): ?array;

    /** Cancel fan-out work, retaining its history; already dispatched child work may finish. */
    public function cancelTaskGroup(string $key): void;

    /** Retry only terminal failed/cancelled work, never reset live or completed tasks. */
    public function retryTaskGroup(string $key): int;
}
