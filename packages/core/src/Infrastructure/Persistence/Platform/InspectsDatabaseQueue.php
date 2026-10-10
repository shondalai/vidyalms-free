<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform;

/** Adapter reuse of the mechanical queue read capability. */
trait InspectsDatabaseQueue
{
    abstract private function queueInspector(): DatabaseScheduledTaskInspector;

    /** @return array<string, mixed> */
    public function queueOverview(): array
    {
        return $this->queueInspector()->overview();
    }

    /**
     * The CountsPendingTasks capability, shared with the queue inspection
     * methods because it is the same table and the same status vocabulary.
     *
     * @return array<string, int>
     */
    public function pendingCountsByType(): array
    {
        return $this->queueInspector()->pendingCountsByType();
    }

    /** @return array<string, mixed> */
    public function taskPage(int $page, int $perPage, ?string $state = null, ?string $type = null): array
    {
        return $this->queueInspector()->page($page, $perPage, $state, $type);
    }

    /** @return array<string, mixed>|null */
    public function taskRecord(int $id): ?array
    {
        return $this->queueInspector()->record($id);
    }

    /** @return array<string, mixed>|null */
    public function taskGroup(string $key): ?array
    {
        return $this->queueInspector()->group($key);
    }

    /** @return array<string, mixed>|null */
    public function latestTaskGroup(string $prefix): ?array
    {
        return $this->queueInspector()->latestGroup($prefix);
    }

    public function cancelTaskGroup(string $key): void
    {
        $this->queueInspector()->cancel($key);
    }

    public function retryTaskGroup(string $key): int
    {
        return $this->queueInspector()->retry($key);
    }
}
