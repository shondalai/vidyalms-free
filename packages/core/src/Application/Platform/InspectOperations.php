<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Scheduler\InspectsScheduledTasks;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;

/** Facts from durable server records, not browser approximations of system health. */
final class InspectOperations
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly SchedulerPort $scheduler,
        private readonly ClockPort $clock,
        private readonly AccessGuard $guard
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(Principal $principal): array
    {
        $this->guard->require($principal, Capability::ADMIN);
        return ['generated_at' => $this->clock->now()->format(DATE_ATOM),
            'scheduler' => $this->scheduler instanceof InspectsScheduledTasks ? $this->scheduler->queueOverview() : ['available' => false],
            'imports' => ['counts' => $this->counts('import_jobs', 'status')],
            'notifications' => ['counts' => $this->counts('notification_queue', 'state')],
            'health_basis' => 'persisted_queue_records',
            'delivery_probe' => null, 'worker_heartbeat' => null,
        ];
    }

    /** @return array<string, mixed> */
    public function jobs(Principal $principal, int $page, int $perPage, ?string $state, ?string $type): array
    {
        $this->guard->require($principal, Capability::ADMIN);
        if ($page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 100) {
            throw ValidationException::forField('page', 'Use page 1 or higher and 1 to 100 rows per page.');
        }
        if ($state !== null && !\in_array($state, ['queued', 'running', 'complete', 'failed', 'cancelled'], true)) {
            throw ValidationException::forField('state', 'Choose a supported queue state.');
        }
        if ($type !== null && (strlen($type) > 100 || preg_match('/^[a-z0-9_.-]+$/iD', $type) !== 1)) {
            throw ValidationException::forField('type', 'Use a registered task type key.');
        }
        if (!$this->scheduler instanceof InspectsScheduledTasks) {
            return ['available' => false, 'items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }
        return ['available' => true] + $this->scheduler->taskPage($page, $perPage, $state, $type);
    }

    /** @return array<string, mixed> */
    public function job(Principal $principal, int $id): array
    {
        $this->guard->require($principal, Capability::ADMIN);
        $row = $this->scheduler instanceof InspectsScheduledTasks ? $this->scheduler->taskRecord($id) : null;
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        return $row ?? throw EntityNotFoundException::for('Retained operation job', $id);
    }

    /** @return array<string, int> */
    private function counts(string $table, string $column): array
    {
        $counts = [];
        $query = Query::from($table)->select([$column])->aggregate(Aggregate::count('amount'))->groupBy($column);
        foreach ($this->database->selectAll($query) as $row) {
            $counts[(string) $row[$column]] = (int) $row['amount'];
        }
        return $counts;
    }
}
