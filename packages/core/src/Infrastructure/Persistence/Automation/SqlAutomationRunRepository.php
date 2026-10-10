<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation;

use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRun;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRunState;
use Shondalai\VidyaLMS\Core\Domain\Automation\CascadeChain;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;

/**
 * The run table, which is the automation engine's intent row.
 *
 * open() is the outbox write: exactly one row inside the transaction that
 * changed the state, by the use case and never from an event listener, because
 * EventBus wraps every listener in try and catch and logs, so an outbox
 * written from a listener commits the business state and loses the row.
 *
 * The unique index is the re-entry guard and this repository is where it is
 * answered rather than thrown: a subject already inside a rule for this
 * occurrence answers null, which is a normal replay and not an error.
 */
final class SqlAutomationRunRepository
{
    public const TABLE = 'automation_runs';

    /** How long a fan-out claim holds before another runner may take the row. */
    public const LEASE_SECONDS = 300;

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * Writes the intent row, or answers null when this rule, occurrence,
     * subject and dry-run flag already has one.
     *
     * The read before the insert is only the cheap path for the ordinary
     * replay; the insert is still attempted, and the unique index is the
     * authority. Two concurrent writers therefore write one row and one of
     * them answers null.
     */
    public function open(AutomationRun $run): ?int
    {
        if ($this->findOccurrence($run) !== null) {
            return null;
        }

        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        try {
            return $this->database->insert(self::TABLE, [
                'rule_id' => $run->ruleId,
                'rule_version' => $run->ruleVersion,
                'trigger_key' => $run->triggerKey,
                'event_ref' => $run->eventReference,
                'occurrence_key' => $run->occurrenceKey,
                'subject_type' => $run->subjectType,
                'subject_id' => $run->subjectId,
                // The person the occurrence is about, which the subject
                // columns cannot hold when the subject is a course.
                'recipient_user_id' => $run->recipientUserId,
                'actor_user_id' => $run->actorUserId,
                'dry_run' => $run->dryRun ? 1 : 0,
                'state' => $run->state->value,
                'cascade_depth' => $run->cascadeDepth,
                'rule_chain' => $run->chain()->toString(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolation) {
            return null;
        }
    }

    public function find(int $id): ?AutomationRun
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('id', '=', $id));

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * The intent rows this fan-out runner has claimed, and nothing else
     * touches.
     *
     * Binned exactly as the notification fan-out is, so one run does bounded
     * work and a rule over a large audience never runs its evaluations inside
     * the transaction that wrote the intent row. The claim is conditional
     * because two runners over one intent row is the normal case on both
     * hosts.
     *
     * @return list<AutomationRun>
     */
    public function claimIntents(string $claimToken, int $limit, int $binIndex = 0, int $binCount = 1): array
    {
        $binCount = max(1, $binCount);
        $binIndex = max(0, $binIndex) % $binCount;

        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->select(['id'])
                ->where('state', '=', AutomationRunState::PendingFanout->value)
                ->orderBy('id')
                ->limit($limit * $binCount),
        );

        $now = $this->clock->now();
        $claimed = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            if ($binCount > 1 && $id % $binCount !== $binIndex) {
                continue;
            }

            if (\count($claimed) >= $limit) {
                break;
            }

            $affected = $this->database->update(self::TABLE, [
                'state' => AutomationRunState::FanningOut->value,
                'started_at' => $now->format(self::DATETIME_FORMAT),
                'updated_at' => $now->format(self::DATETIME_FORMAT),
            ], ['id' => $id, 'state' => AutomationRunState::PendingFanout->value]);

            if ($affected !== 1) {
                continue;
            }

            $run = $this->find($id);

            if ($run !== null) {
                $claimed[] = $run;
            }
        }

        return $claimed;
    }

    public function transition(int $id, AutomationRunState $state): void
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);
        $values = ['state' => $state->value, 'updated_at' => $now];

        if ($state->isTerminal()) {
            $values['finished_at'] = $now;
        }

        $this->database->update(self::TABLE, $values, ['id' => $id]);
    }

    /** One of matched_count, executed_count, skipped_count, failed_count. */
    public function addToCounter(int $id, string $column, int $by = 1): void
    {
        if (!\in_array($column, ['matched_count', 'executed_count', 'skipped_count', 'failed_count'], true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('"%s" is not an automation run counter.', $column));
        }

        $row = $this->database->selectOne(Query::from(self::TABLE)->where('id', '=', $id));

        if ($row === null) {
            return;
        }

        $this->database->update(self::TABLE, [
            $column => (int) ($row[$column] ?? 0) + $by,
            'updated_at' => $this->clock->now()->format(self::DATETIME_FORMAT),
        ], ['id' => $id]);
    }

    public function appendLog(int $id, string $line): void
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->select(['log'])->where('id', '=', $id));

        if ($row === null) {
            return;
        }

        $existing = \is_string($row['log'] ?? null) ? (string) $row['log'] : '';

        $this->database->update(self::TABLE, [
            'log' => $existing === '' ? $line : $existing . "\n" . $line,
            'updated_at' => $this->clock->now()->format(self::DATETIME_FORMAT),
        ], ['id' => $id]);
    }

    /**
     * How many real runs this rule has already had for this subject.
     *
     * Reads only rows whose dry_run is 0, because a preview never occupies the
     * re-entry guard and never counts against times_per_subject: the whole
     * point of putting the flag in the three keys is that rehearsing a rule
     * costs the subject nothing.
     */
    public function countForSubject(int $ruleId, ?string $subjectType, ?int $subjectId): int
    {
        $query = Query::from(self::TABLE)
            ->where('rule_id', '=', $ruleId)
            ->where('dry_run', '=', 0);

        $query = $subjectType === null
            ? $query->whereNull('subject_type')
            : $query->where('subject_type', '=', $subjectType);

        $query = $subjectId === null
            ? $query->whereNull('subject_id')
            : $query->where('subject_id', '=', $subjectId);

        return $this->database->count($query);
    }

    /** Every real run this rule has had, for the total_completions cap. */
    public function countForRule(int $ruleId): int
    {
        return $this->database->count(
            Query::from(self::TABLE)->where('rule_id', '=', $ruleId)->where('dry_run', '=', 0),
        );
    }

    /**
     * When this rule last ran for this subject, for the per-subject cooldown.
     *
     * Loop protection mechanism three, and it reads created_at rather than
     * finished_at so a run still in flight still holds the window: a cooldown
     * that only counted finished runs would let a cascade re-enter while the
     * first pass was still fanning out.
     */
    public function lastRunAtForSubject(int $ruleId, ?string $subjectType, ?int $subjectId): ?\DateTimeImmutable
    {
        $query = Query::from(self::TABLE)
            ->select(['created_at'])
            ->where('rule_id', '=', $ruleId)
            ->where('dry_run', '=', 0)
            ->orderBy('created_at', Query::SORT_DESC)
            ->limit(1);

        $query = $subjectType === null
            ? $query->whereNull('subject_type')
            : $query->where('subject_type', '=', $subjectType);

        $query = $subjectId === null
            ? $query->whereNull('subject_id')
            : $query->where('subject_id', '=', $subjectId);

        $row = $this->database->selectOne($query);
        $value = $row['created_at'] ?? null;

        return \is_string($value) && $value !== ''
            ? new \DateTimeImmutable($value, new \DateTimeZone('UTC'))
            : null;
    }

    /**
     * @return list<AutomationRun>
     */
    public function forRule(int $ruleId, int $limit = 50): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('rule_id', '=', $ruleId)
                ->orderBy('id', Query::SORT_DESC)
                ->limit($limit),
        );

        return array_map(static fn (array $row): AutomationRun => self::hydrate($row), $rows);
    }

    /**
     * Returns expired-lease fan-out rows to pending_fanout.
     *
     * The recovery claim cannot be "fanning_out to fanning_out": the predicate
     * would be satisfied by the very value the update writes, so every runner
     * that saw the expired row would claim it. Moving it back is what makes
     * the ordinary claim the only claim there is.
     */
    public function reclaimExpiredFanouts(int $limit): int
    {
        $now = $this->clock->now();
        $cutoff = $now->modify('-' . self::LEASE_SECONDS . ' seconds')->format(self::DATETIME_FORMAT);
        $nowString = $now->format(self::DATETIME_FORMAT);
        $reclaimed = 0;

        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->select(['id'])
                ->where('state', '=', AutomationRunState::FanningOut->value)
                ->where('started_at', '<', $cutoff)
                ->orderBy('started_at')
                ->limit($limit),
        );

        foreach ($rows as $row) {
            $reclaimed += $this->database->update(self::TABLE, [
                'state' => AutomationRunState::PendingFanout->value,
                'updated_at' => $nowString,
            ], ['id' => (int) $row['id'], 'state' => AutomationRunState::FanningOut->value]) === 1 ? 1 : 0;
        }

        return $reclaimed;
    }

    /** @return array<string, mixed>|null */
    private function findOccurrence(AutomationRun $run): ?array
    {
        $query = Query::from(self::TABLE)
            ->select(['id'])
            ->where('rule_id', '=', $run->ruleId)
            ->where('occurrence_key', '=', $run->occurrenceKey)
            ->where('dry_run', '=', $run->dryRun ? 1 : 0);

        $query = $run->subjectType === null
            ? $query->whereNull('subject_type')
            : $query->where('subject_type', '=', $run->subjectType);

        $query = $run->subjectId === null
            ? $query->whereNull('subject_id')
            : $query->where('subject_id', '=', $run->subjectId);

        return $this->database->selectOne($query);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): AutomationRun
    {
        return new AutomationRun(
            (int) $row['id'],
            (int) $row['rule_id'],
            (int) $row['rule_version'],
            (string) $row['trigger_key'],
            (string) $row['event_ref'],
            (string) $row['occurrence_key'],
            $row['subject_type'] === null ? null : (string) $row['subject_type'],
            $row['subject_id'] === null ? null : (int) $row['subject_id'],
            $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            (int) ($row['dry_run'] ?? 0) === 1,
            AutomationRunState::tryFrom((string) ($row['state'] ?? 'pending_fanout'))
                ?? AutomationRunState::PendingFanout,
            (int) ($row['cascade_depth'] ?? 0),
            CascadeChain::fromString($row['rule_chain'] === null ? null : (string) $row['rule_chain']),
            (int) ($row['matched_count'] ?? 0),
            (int) ($row['executed_count'] ?? 0),
            (int) ($row['skipped_count'] ?? 0),
            (int) ($row['failed_count'] ?? 0),
            $row['log'] === null ? null : (string) $row['log'],
            self::instant($row['started_at'] ?? null),
            self::instant($row['finished_at'] ?? null),
            ($row['recipient_user_id'] ?? null) === null ? null : (int) $row['recipient_user_id'],
        );
    }

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) && $value !== ''
            ? new \DateTimeImmutable($value, new \DateTimeZone('UTC'))
            : null;
    }
}
