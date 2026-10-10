<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Scheduler\UnresolvedTaskType;

/** Shared query mechanics; the adapter supplies its own queue table, not a core schema table. */
final class DatabaseScheduledTaskInspector
{
    private const STATES = [
        'pending' => 'queued', 'running' => 'running', 'done' => 'complete', 'failed' => 'failed', 'cancelled' => 'cancelled',
    ];
    /**
     * last_error is read and never published. A failed row answers a fixed
     * sentence pointing at the protected server log, because
     * InspectsScheduledTasks promises to expose no raw error; a pending row
     * stamped by a drain that could not resolve its task type answers
     * UnresolvedTaskType::diagnostic(), which names only the task type this
     * same row already carries. Without the column a job left pending because
     * its package is missing is indistinguishable on the operations screen
     * from one that is merely waiting its turn.
     */
    private const COLUMNS = [
        'id', 'job_type', 'group_key', 'status', 'attempts', 'run_at', 'reserved_at', 'finished_at', 'created_at', 'updated_at',
        'last_error',
    ];

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly string $table,
        private readonly ?int $retentionDays = null
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $counts = $this->counts(Query::from($this->table));
        $now = $this->clock->now();
        $counts['due'] = $this->database->count(Query::from($this->table)
            ->where('status', '=', 'pending')->where('run_at', '<=', $now->format('Y-m-d H:i:s')));
        $counts['stalled'] = $this->database->count(Query::from($this->table)
            ->where('status', '=', 'running')->where('reserved_at', '<', $now->modify('-30 minutes')->format('Y-m-d H:i:s')));

        return ['available' => true, 'counts' => $counts,
            'retention_days' => $this->retentionDays, 'observed_at' => $now->format(DATE_ATOM)];
    }

    /**
     * How much unstarted work the queue is holding, per task type.
     *
     * One grouped count rather than a query per task type, because the sweep
     * turn that asks this asks about every registered periodic handler at
     * once. Types with nothing pending are absent rather than zero, so the
     * caller reads a missing key as "nothing waiting" and never as "the
     * scheduler cannot say".
     *
     * @return array<string, int>
     */
    public function pendingCountsByType(): array
    {
        $counts = [];
        $rows = $this->database->selectAll(
            Query::from($this->table)
                ->select(['job_type'])
                ->where('status', '=', 'pending')
                ->aggregate(Aggregate::count('amount'))
                ->groupBy('job_type')
        );

        foreach ($rows as $row) {
            $type = (string) ($row['job_type'] ?? '');

            if ($type !== '') {
                $counts[$type] = (int) ($row['amount'] ?? 0);
            }
        }

        return $counts;
    }

    /** @return array<string, mixed> */
    public function page(int $page, int $perPage, ?string $state, ?string $type): array
    {
        $query = Query::from($this->table)->select(self::COLUMNS);
        if ($state !== null) {
            $stored = array_search($state, self::STATES, true);
            if ($stored === false) {
                throw new \InvalidArgumentException('Invalid queue state.');
            }
            $query->where('status', '=', $stored);
        }
        if ($type !== null) {
            $query->where('job_type', '=', $type);
        }
        $total = $this->database->count(clone $query);
        $rows = $this->database->selectAll($query->orderBy('id', Query::SORT_DESC)->limit($perPage)->offset(($page - 1) * $perPage));

        return ['items' => array_map(self::present(...), $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @return array<string, mixed>|null */
    public function record(int $id): ?array
    {
        $row = $this->database->selectOne(Query::from($this->table)->select(self::COLUMNS)->where('id', '=', $id));
        return $row === null ? null : self::present($row);
    }

    /** @return array<string, mixed>|null */
    public function group(string $key): ?array
    {
        $root = $this->database->selectOne(Query::from($this->table)->select(self::COLUMNS)->where('dedupe_key', '=', $key));
        if ($root === null) {
            return null;
        }
        $counts = $this->counts(Query::from($this->table)->where('group_key', '=', $key));
        $fanout = $this->counts(Query::from($this->table)
            ->where('group_key', '=', $key)->where('job_type', '=', (string) $root['job_type']));
        $active = $counts['queued'] + $counts['running'];
        $state = match (true) {
            $active > 0 && $counts['complete'] + $counts['running'] + $counts['failed'] > 0 => 'running',
            $active > 0 => 'queued',
            $counts['failed'] > 0 => 'failed',
            $counts['cancelled'] > 0 => 'cancelled',
            $counts['complete'] > 0 => 'complete',
            default => 'unknown',
        };

        return ['key' => $key, 'state' => $state, 'counts' => $counts, 'fanout' => $fanout,
            'selection_complete' => $fanout['queued'] + $fanout['running'] + $fanout['failed'] + $fanout['cancelled'] === 0
                && $fanout['complete'] > 0,
            'root_job' => self::present($root), 'retention_days' => $this->retentionDays,
            'progress_basis' => 'persisted_task_counts', 'percent' => null];
    }

    /** @return array<string, mixed>|null */
    public function latestGroup(string $prefix): ?array
    {
        if (preg_match('/^[a-z0-9:.-]+:$/D', $prefix) !== 1) {
            throw new \InvalidArgumentException('Use a trusted task-group prefix.');
        }
        $row = $this->database->selectOne(Query::from($this->table)->select(['group_key'])
            ->where('group_key', 'LIKE', $prefix . '%')->aggregate(Aggregate::min('id', 'root_id'))
            ->groupBy('group_key')->orderBy('root_id', Query::SORT_DESC)->limit(1));
        return $row === null ? null : $this->group((string) $row['group_key']);
    }

    public function cancel(string $key): void
    {
        $root = $this->database->selectOne(Query::from($this->table)->select(['id', 'job_type'])->where('dedupe_key', '=', $key));
        if ($root === null) {
            return;
        }
        $fanout = Query::from($this->table)->where('group_key', '=', $key)
            ->where('job_type', '=', (string) $root['job_type'])->whereIn('status', ['pending', 'running']);
        if ($this->database->count($fanout) === 0) {
            return;
        }
        $stamp = $this->clock->now()->format('Y-m-d H:i:s');
        $values = ['status' => 'cancelled', 'finished_at' => $stamp, 'updated_at' => $stamp];
        // Stamp the cancellation marker even if a bounded fan-out is in flight.
        // Every later schedule in this group checks that marker before enqueueing.
        $this->database->update($this->table, $values, ['id' => $root['id']]);
        $this->database->update($this->table, $values, ['group_key' => $key, 'job_type' => $root['job_type'], 'status' => 'pending']);
    }

    public function cancelled(string $key): bool
    {
        return $this->database->count(Query::from($this->table)->where('dedupe_key', '=', $key)->where('status', '=', 'cancelled')) > 0;
    }

    public function retry(string $key): int
    {
        $stamp = $this->clock->now()->format('Y-m-d H:i:s');
        $values = ['status' => 'pending', 'attempts' => 0, 'last_error' => null, 'reserved_at' => null,
            'finished_at' => null, 'run_at' => $stamp, 'updated_at' => $stamp];
        $count = 0;
        foreach (['failed', 'cancelled'] as $state) {
            $count += $this->database->update($this->table, $values, ['group_key' => $key, 'status' => $state]);
        }
        return $count;
    }

    /** @return array<string, int> */
    private function counts(Query $query): array
    {
        $counts = array_fill_keys(array_values(self::STATES), 0);
        foreach ($this->database->selectAll($query->select(['status'])->aggregate(Aggregate::count('amount'))->groupBy('status')) as $row) {
            $state = self::STATES[(string) ($row['status'] ?? '')] ?? null;
            if ($state !== null) {
                $counts[$state] = (int) $row['amount'];
            }
        }
        $counts['total'] = array_sum($counts);
        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        return ['id' => (int) $row['id'], 'type' => (string) $row['job_type'], 'group_key' => $row['group_key'],
            'state' => self::STATES[(string) $row['status']] ?? 'unknown', 'attempts' => (int) $row['attempts'],
            'scheduled_at' => $row['run_at'], 'started_at' => $row['reserved_at'], 'finished_at' => $row['finished_at'],
            'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
            'audit_url' => '/manage/operations/jobs/' . (int) $row['id'],
            'diagnostic' => self::diagnostic($row)];
    }

    /**
     * The one line the operations surface may say about a row.
     *
     * Three answers and no fourth: a failed job points at the server log
     * without quoting it, a job left pending because nothing answers to its
     * task type says so and says what to do, and everything else says nothing
     * at all. A requeued job after a transient failure falls in the third
     * case deliberately: it is pending, it carries an error, and it is going
     * to run again by itself, so there is nothing for an operator to do.
     *
     * @param array<string, mixed> $row
     */
    private static function diagnostic(array $row): ?string
    {
        if ($row['status'] === 'failed') {
            return 'Task failed. Review the protected server logs using this job id.';
        }

        $stored = $row['last_error'] ?? null;

        if ($row['status'] === 'pending' && UnresolvedTaskType::isUnresolved(\is_string($stored) ? $stored : null)) {
            return UnresolvedTaskType::diagnostic((string) $row['job_type']);
        }

        return null;
    }
}
