<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation;

use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationActionRecord;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The audit, one row per executed step.
 *
 * It is deliberately not the idempotency ledger. The ledger has to be written
 * before the side effect inside a small transaction, and this carries before
 * and after state that the ledger has no business holding, so idem_key here is
 * indexed as a lookup and never as a constraint. The two are joined by the
 * digest and nothing else.
 *
 * Every row names the identity the step ran under. The failure mode the
 * idempotency literature names is a step gated under one business identity and
 * completed under another, undetectable after the fact, and recording both the
 * authoriser and the subject is what closes it.
 */
final class SqlAutomationActionLogRepository
{
    public const TABLE = 'automation_action_log';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function record(AutomationActionRecord $entry): int
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        return $this->database->insert(self::TABLE, [
            'queue_id' => $entry->queueId,
            'run_id' => $entry->runId,
            'rule_id' => $entry->ruleId,
            'rule_version' => $entry->ruleVersion,
            'step_index' => $entry->stepIndex,
            'action_key' => $entry->actionKey,
            'subject_type' => $entry->subjectType,
            'subject_id' => $entry->subjectId,
            'actor_user_id' => $entry->actorUserId,
            'idem_key' => $entry->idemKey,
            'success' => $entry->success ? 1 : 0,
            'before_state' => $entry->beforeState === []
                ? null
                : json_encode(StoredShape::wrap($entry->beforeState, $entry->stateVersion), \JSON_THROW_ON_ERROR),
            'after_state' => $entry->afterState === []
                ? null
                : json_encode(StoredShape::wrap($entry->afterState, $entry->stateVersion), \JSON_THROW_ON_ERROR),
            'state_version' => $entry->stateVersion,
            'error_code' => $entry->errorCode,
            'error_message' => $entry->errorMessage,
            'ai_run_id' => $entry->aiRunId,
            'executed_at' => $entry->executedAt->format(self::DATETIME_FORMAT),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return list<AutomationActionRecord>
     */
    public function forRun(int $runId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)->where('run_id', '=', $runId)->orderBy('id'),
        );

        return array_map(static fn (array $row): AutomationActionRecord => self::hydrate($row), $rows);
    }

    /**
     * @return list<AutomationActionRecord>
     */
    public function forRule(int $ruleId, int $limit = 100): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('rule_id', '=', $ruleId)
                ->orderBy('executed_at', Query::SORT_DESC)
                ->orderBy('id', Query::SORT_DESC)
                ->limit($limit),
        );

        return array_map(static fn (array $row): AutomationActionRecord => self::hydrate($row), $rows);
    }

    /** Every audit row written under one idempotency digest, which should be one. */
    public function countForIdemKey(string $idemKey): int
    {
        return $this->database->count(Query::from(self::TABLE)->where('idem_key', '=', $idemKey));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): AutomationActionRecord
    {
        return new AutomationActionRecord(
            $row['queue_id'] === null ? null : (int) $row['queue_id'],
            $row['run_id'] === null ? null : (int) $row['run_id'],
            $row['rule_id'] === null ? null : (int) $row['rule_id'],
            (int) ($row['rule_version'] ?? 1),
            (int) ($row['step_index'] ?? 0),
            (string) $row['action_key'],
            $row['subject_type'] === null ? null : (string) $row['subject_type'],
            $row['subject_id'] === null ? null : (int) $row['subject_id'],
            $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            (string) $row['idem_key'],
            (int) ($row['success'] ?? 0) === 1,
            new \DateTimeImmutable((string) $row['executed_at'], new \DateTimeZone('UTC')),
            self::decode($row['before_state'] ?? null),
            self::decode($row['after_state'] ?? null),
            (int) ($row['state_version'] ?? 1),
            $row['error_code'] === null ? null : (string) $row['error_code'],
            $row['error_message'] === null ? null : (string) $row['error_message'],
            $row['ai_run_id'] === null ? null : (int) $row['ai_run_id'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(mixed $value): array
    {
        if (\is_array($value)) {
            return StoredShape::bodyOf($value);
        }

        if (!\is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return \is_array($decoded) ? StoredShape::bodyOf($decoded) : [];
    }
}
