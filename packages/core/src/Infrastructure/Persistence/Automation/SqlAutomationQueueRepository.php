<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation;

use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStep;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStepState;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The step queue.
 *
 * This is the notification outbox's repository with action_key where
 * channel_key was, and the reasoning transfers line for line: the insert is
 * idempotent by the dedupe digest and the unique index on it is the authority
 * rather than the read; the claim is one conditional statement whose predicate
 * names a state the statement moves the row out of; and a lease that expires
 * returns the row to pending rather than being claimed a second time from the
 * state it is already in.
 *
 * Reads are cursored rather than offset-paged, because the sweep and the drain
 * both walk a table that is being written while they walk it, and offset makes
 * the engine build and discard every skipped row while letting rows shift
 * between pages.
 */
final class SqlAutomationQueueRepository
{
    public const TABLE = 'automation_queue';

    /** How long a claim holds before another runner may take the row. */
    public const LEASE_SECONDS = 300;

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * Writes the step, or answers null when its digest is already taken.
     *
     * Null is the normal replay answer and not an error: the step has already
     * been enqueued for this run, subject and occurrence, and a terminal row
     * keeps its digest for ever so the step is never re-enqueued behind an
     * operator's back.
     */
    public function enqueue(AutomationStep $step): ?int
    {
        if ($this->findByDedupeKey($step->dedupeKey) !== null) {
            return null;
        }

        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        try {
            return $this->database->insert(self::TABLE, [
                'run_id' => $step->runId,
                'rule_id' => $step->ruleId,
                'rule_version' => $step->ruleVersion,
                'step_index' => $step->stepIndex,
                'action_key' => $step->actionKey,
                'subject_type' => $step->subjectType,
                'subject_id' => $step->subjectId,
                'recipient_user_id' => $step->recipientUserId,
                'params' => $step->params === []
                    ? null
                    : json_encode(StoredShape::wrap($step->params, $step->paramsVersion), \JSON_THROW_ON_ERROR),
                'params_version' => $step->paramsVersion,
                'occurrence_key' => $step->occurrenceKey,
                'dedupe_key' => $step->dedupeKey,
                'idem_key' => $step->idemKey,
                'run_at' => $step->runAt->format(self::DATETIME_FORMAT),
                'state' => $step->state->value,
                'attempts' => $step->attempts,
                'next_attempt_at' => $step->nextAttemptAt?->format(self::DATETIME_FORMAT),
                'cascade_depth' => $step->cascadeDepth,
                'dry_run' => $step->dryRun ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolation) {
            return null;
        }
    }

    public function find(int $id): ?AutomationStep
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('id', '=', $id));

        return $row === null ? null : self::hydrate($row);
    }

    public function findByDedupeKey(string $dedupeKey): ?AutomationStep
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('dedupe_key', '=', $dedupeKey));

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Pending steps whose moment has come, after a cursor.
     *
     * The cursor is the row id, which is stable and unique, and the chunk size
     * is the caller's rather than adapted silently to server capacity: an
     * operator cannot reason about a number the product will not show them.
     *
     * @return list<AutomationStep>
     */
    public function due(int $limit, int $afterId = 0): array
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                // Deferred is read here as well as pending, because a
                // deferral is not a failure and the row must come back. It
                // used to be written as pending with the deferral reason in
                // suppressed_reason, so the enum case was read and never
                // written and an operator filtering the queue on deferred saw
                // nothing while the index mixed deferrals with suppressions.
                ->whereIn('state', [
                    AutomationStepState::Pending->value,
                    AutomationStepState::Deferred->value,
                ])
                ->where('run_at', '<=', $now)
                ->where('id', '>', $afterId)
                ->orderBy('id')
                ->limit($limit),
        );

        $due = [];

        foreach ($rows as $row) {
            $nextAttempt = $row['next_attempt_at'] ?? null;

            if (\is_string($nextAttempt) && $nextAttempt !== '' && $nextAttempt > $now) {
                continue;
            }

            $due[] = self::hydrate($row);
        }

        return $due;
    }

    /**
     * The claim. Zero affected rows means another runner owns it and the row
     * is skipped, which is what makes two concurrent drains over one pending
     * row produce exactly one effect.
     */
    public function claim(int $id, string $claimToken): bool
    {
        $now = $this->clock->now();
        $values = [
            'state' => AutomationStepState::Claimed->value,
            'claim_token' => $claimToken,
            'claimed_at' => $now->format(self::DATETIME_FORMAT),
            'lease_expires_at' => $now->modify('+' . self::LEASE_SECONDS . ' seconds')
                ->format(self::DATETIME_FORMAT),
            'updated_at' => $now->format(self::DATETIME_FORMAT),
        ];

        // Two conditional statements rather than one with an IN predicate,
        // because the port's update takes an equality map and a claim whose
        // predicate did not name the state it moves the row out of is not a
        // claim at all. Deferred is claimable for the same reason due() reads
        // it: a deferral is not a failure.
        foreach ([AutomationStepState::Pending, AutomationStepState::Deferred] as $from) {
            if ($this->database->update(self::TABLE, $values, ['id' => $id, 'state' => $from->value]) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns expired-lease rows to pending, conditionally on the claim token
     * the dead runner left behind.
     *
     * This is the path a step killed between its idempotency claim and its
     * effect comes back through, and it is why the automation scope's claim
     * TTL is the queue's own lease horizon rather than a wall clock.
     *
     * It touches this table and never idempotency_keys, and that is correct
     * rather than an omission: the claim and the effect commit inside one
     * transaction in RunAutomationStep, so a killed step rolled both back and
     * there is no claim left for a lease to release. The docblock here used to
     * say the lease released the ledger claim, which was the only thing that
     * would have made the three-commit version safe and was never true.
     */
    public function reclaimExpiredLeases(int $limit): int
    {
        $now = $this->clock->now();
        $nowString = $now->format(self::DATETIME_FORMAT);
        $reclaimed = 0;

        foreach ([AutomationStepState::Claimed, AutomationStepState::Executing] as $state) {
            $rows = $this->database->selectAll(
                Query::from(self::TABLE)
                    ->select(['id', 'claim_token'])
                    ->where('state', '=', $state->value)
                    ->where('lease_expires_at', '<', $nowString)
                    ->orderBy('lease_expires_at')
                    ->limit($limit),
            );

            $target = $state->releasesTo();

            if ($target === null) {
                continue;
            }

            foreach ($rows as $row) {
                $reclaimed += $this->database->update(self::TABLE, [
                    'state' => $target->value,
                    'claim_token' => null,
                    'lease_expires_at' => null,
                    'updated_at' => $nowString,
                ], [
                    'id' => (int) $row['id'],
                    'state' => $state->value,
                    'claim_token' => $row['claim_token'],
                ]) === 1 ? 1 : 0;
            }
        }

        return $reclaimed;
    }

    public function markExecuting(int $id): void
    {
        $this->set($id, ['state' => AutomationStepState::Executing->value]);
    }

    public function markExecuted(int $id, int $attempts): void
    {
        $this->set($id, [
            'state' => AutomationStepState::Executed->value,
            'attempts' => $attempts,
            'executed_at' => $this->clock->now()->format(self::DATETIME_FORMAT),
            'claim_token' => null,
            'lease_expires_at' => null,
            'last_error' => null,
        ]);
    }

    public function markSuppressed(int $id, string $reason): void
    {
        $this->set($id, [
            'state' => AutomationStepState::Suppressed->value,
            'suppressed_reason' => $reason,
            'claim_token' => null,
            'lease_expires_at' => null,
        ]);
    }

    /**
     * Back to pending with a moment, the attempt counter untouched.
     *
     * A deferral is not a failure: it does not consume an attempt and it does
     * not reach the failure policy. A ConflictException from the idempotency
     * store maps here, with next_attempt_at set to the claim expiry, and never
     * to failed_permanent.
     *
     * The state written is `deferred` and not `pending`. The enum case was
     * declared and read and never written, so the queue screen's deferred
     * filter matched nothing and the (rule_id, suppressed_reason) index mixed
     * deferrals with genuine suppressions; due() and claim() both read the
     * case, so a deferred row still comes back when its moment arrives.
     */
    public function markDeferred(int $id, \DateTimeImmutable $nextAttemptAt, string $reason): void
    {
        $this->set($id, [
            'state' => AutomationStepState::Deferred->value,
            'run_at' => $nextAttemptAt->format(self::DATETIME_FORMAT),
            'next_attempt_at' => $nextAttemptAt->format(self::DATETIME_FORMAT),
            'suppressed_reason' => $reason,
            'claim_token' => null,
            'lease_expires_at' => null,
        ]);
    }

    public function markRetry(int $id, int $attempts, \DateTimeImmutable $nextAttemptAt, string $error): void
    {
        $this->set($id, [
            'state' => AutomationStepState::Pending->value,
            'attempts' => $attempts,
            'run_at' => $nextAttemptAt->format(self::DATETIME_FORMAT),
            'next_attempt_at' => $nextAttemptAt->format(self::DATETIME_FORMAT),
            'last_error' => $error,
            'claim_token' => null,
            'lease_expires_at' => null,
        ]);
    }

    public function markParked(int $id, int $attempts, string $error): void
    {
        $this->set($id, [
            'state' => AutomationStepState::Parked->value,
            'attempts' => $attempts,
            'last_error' => $error,
            'claim_token' => null,
            'lease_expires_at' => null,
        ]);
    }

    public function markFailedPermanent(int $id, int $attempts, string $error): void
    {
        $this->set($id, [
            'state' => AutomationStepState::FailedPermanent->value,
            'attempts' => $attempts,
            'last_error' => $error,
            'claim_token' => null,
            'lease_expires_at' => null,
        ]);
    }

    public function markAwaitingApproval(int $id): void
    {
        $this->set($id, [
            'state' => AutomationStepState::AwaitingApproval->value,
            'claim_token' => null,
            'lease_expires_at' => null,
        ]);
    }

    /**
     * The way out of awaiting_approval and parked, and the half of the
     * human-in-the-loop guarantee that was missing.
     *
     * A step parked before its effect had no exit at all: due() read only
     * pending, the lease reclaim walked only claimed and executing, and the
     * only transition out was cancelForRule, reached by disarming or archiving
     * the whole rule. So an approval-gated action waited for ever and its
     * effect never ran, which is the opposite failure to the one the gate
     * exists to prevent.
     *
     * The move is conditional on the state it releases from, so two reviewers
     * releasing one row release it once, and it answers false rather than
     * throwing when the row has moved on.
     */
    public function release(int $id, \DateTimeImmutable $runAt): bool
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);
        $values = [
            'state' => AutomationStepState::Pending->value,
            'run_at' => $runAt->format(self::DATETIME_FORMAT),
            'next_attempt_at' => null,
            'suppressed_reason' => null,
            'claim_token' => null,
            'claimed_at' => null,
            'lease_expires_at' => null,
            'updated_at' => $now,
        ];

        foreach ([AutomationStepState::AwaitingApproval, AutomationStepState::Parked] as $from) {
            if ($this->database->update(self::TABLE, $values, ['id' => $id, 'state' => $from->value]) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every step of one run, in step order, for the run log.
     *
     * @return list<AutomationStep>
     */
    public function forRun(int $runId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)->where('run_id', '=', $runId)->orderBy('step_index')->orderBy('id'),
        );

        return array_map(static fn (array $row): AutomationStep => self::hydrate($row), $rows);
    }

    /**
     * Cancel every row of one rule that has not run yet, and answer how many.
     *
     * Disarming and archiving both cancel rather than leave rows to fire: a
     * rule somebody switched off that still delivers for the next two hours is
     * a rule the operator cannot stop. Rows already executed, suppressed or
     * failed are history and are left exactly as they are, which is why the
     * predicate names the three states that have not happened yet rather than
     * excluding the ones that have.
     */
    public function cancelForRule(int $ruleId): int
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);
        $cancelled = 0;

        foreach (
            $this->database->selectAll(
                Query::from(self::TABLE)
                    ->select(['id'])
                    ->where('rule_id', '=', $ruleId)
                    ->whereIn('state', [
                        AutomationStepState::Pending->value,
                        AutomationStepState::AwaitingApproval->value,
                        AutomationStepState::Deferred->value,
                    ]),
            ) as $row
        ) {
            $this->database->update(self::TABLE, [
                'state' => AutomationStepState::Cancelled->value,
                'claim_token' => null,
                'claimed_at' => null,
                'lease_expires_at' => null,
                'updated_at' => $now,
            ], ['id' => (int) $row['id']]);
            $cancelled++;
        }

        return $cancelled;
    }

    /**
     * The review queue: steps parked or awaiting a named person.
     *
     * @return list<AutomationStep>
     */
    public function awaitingPerson(int $limit = 100): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->whereIn('state', [
                    AutomationStepState::AwaitingApproval->value,
                    AutomationStepState::Parked->value,
                ])
                ->orderBy('id')
                ->limit($limit),
        );

        return array_map(static fn (array $row): AutomationStep => self::hydrate($row), $rows);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function set(int $id, array $values): void
    {
        $values['updated_at'] = $this->clock->now()->format(self::DATETIME_FORMAT);
        $this->database->update(self::TABLE, $values, ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): AutomationStep
    {
        $params = $row['params'] ?? null;
        $decoded = [];

        if (\is_string($params) && $params !== '') {
            $json = json_decode($params, true);
            $decoded = \is_array($json) ? StoredShape::bodyOf($json) : [];
        } elseif (\is_array($params)) {
            $decoded = StoredShape::bodyOf($params);
        }

        return new AutomationStep(
            (int) $row['id'],
            (int) $row['run_id'],
            (int) $row['rule_id'],
            (int) ($row['rule_version'] ?? 1),
            (int) $row['step_index'],
            (string) $row['action_key'],
            $row['subject_type'] === null ? null : (string) $row['subject_type'],
            $row['subject_id'] === null ? null : (int) $row['subject_id'],
            $decoded,
            (int) ($row['params_version'] ?? 1),
            (string) $row['occurrence_key'],
            (string) $row['dedupe_key'],
            (string) $row['idem_key'],
            new \DateTimeImmutable((string) $row['run_at'], new \DateTimeZone('UTC')),
            AutomationStepState::tryFrom((string) ($row['state'] ?? 'pending')) ?? AutomationStepState::Pending,
            (int) ($row['attempts'] ?? 0),
            self::instant($row['next_attempt_at'] ?? null),
            (int) ($row['cascade_depth'] ?? 0),
            (int) ($row['dry_run'] ?? 0) === 1,
            $row['suppressed_reason'] === null ? null : (string) $row['suppressed_reason'],
            $row['last_error'] === null ? null : (string) $row['last_error'],
            $row['claim_token'] === null ? null : (string) $row['claim_token'],
            self::instant($row['lease_expires_at'] ?? null),
            self::instant($row['executed_at'] ?? null),
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
