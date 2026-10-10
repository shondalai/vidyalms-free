<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Presenter;

use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The wire shapes of the automation surface.
 *
 * Every stored document travels with the version it was written at, because a
 * client that reads a params bag with no version cannot tell an old shape from
 * a current one and will write back whichever it happened to render.
 *
 * Nothing here reads a registry or a database: it maps rows the read model
 * already fetched. The one judgement it makes is the null-or-integer coercion
 * a driver's string columns need, and it makes it in one place rather than in
 * every handler.
 */
final class AutomationPresenter
{
    /**
     * @param array<string, mixed>             $row      the automation_rules row
     * @param list<array<string, mixed>>       $steps    its step rows, in step order
     * @param list<array<string, mixed>>       $versions its version rows
     * @param list<string>                     $missing  keys no registry answers
     *
     * @return array<string, mixed>
     */
    public function rule(array $row, array $steps, array $versions, array $missing, ?string $testResult): array
    {
        return [
            'id' => self::int($row['id'] ?? 0),
            'rule_key' => self::string($row['rule_key'] ?? ''),
            'title' => self::string($row['title'] ?? ''),
            'trigger_key' => self::string($row['trigger_key'] ?? ''),
            'state' => self::string($row['state'] ?? 'disabled'),
            'active' => self::bool($row['active'] ?? false),
            'version' => self::int($row['version'] ?? 1),
            'definition_version' => self::int($row['definition_version'] ?? 1),
            'conditions' => self::document($row['conditions'] ?? null),
            'conditions_version' => self::int($row['conditions_version'] ?? 1),
            'schedule_kind' => self::string($row['schedule_kind'] ?? 'immediate'),
            'anchor_key' => self::nullableString($row['anchor_key'] ?? null),
            'schedule' => self::document($row['schedule'] ?? null),
            'schedule_version' => self::int($row['schedule_version'] ?? 1),
            'subject_binding' => self::string($row['subject_binding'] ?? 'learner'),
            'reentry' => self::string($row['reentry'] ?? 'never'),
            'times_per_subject' => self::nullableInt($row['times_per_subject'] ?? null),
            'total_completions' => self::nullableInt($row['total_completions'] ?? null),
            'cooldown_seconds' => self::int($row['cooldown_seconds'] ?? 0),
            'run_order' => self::int($row['run_order'] ?? 100),
            'scope_type' => self::string($row['scope_type'] ?? 'site'),
            'scope_id' => self::int($row['scope_id'] ?? 0),
            'course_id' => self::nullableInt($row['course_id'] ?? null),
            'cohort_id' => self::nullableInt($row['cohort_id'] ?? null),
            'organisation_id' => self::nullableInt($row['organisation_id'] ?? null),
            'max_ai_runs_per_occurrence' => self::nullableInt($row['max_ai_runs_per_occurrence'] ?? null),
            'max_ai_runs_per_arming' => self::nullableInt($row['max_ai_runs_per_arming'] ?? null),
            'armed_by' => self::nullableInt($row['armed_by'] ?? null),
            'armed_at' => self::nullableString($row['armed_at'] ?? null),
            'armed_digest' => self::nullableString($row['armed_digest'] ?? null),
            'last_dry_run_at' => self::nullableString($row['last_dry_run_at'] ?? null),
            'counters' => [
                'queued' => self::int($row['queued_count'] ?? 0),
                'executed' => self::int($row['executed_count'] ?? 0),
                'suppressed' => self::int($row['suppressed_count'] ?? 0),
                'failed' => self::int($row['failed_count'] ?? 0),
            ],
            'actions' => array_map($this->step(...), $steps),
            'missing_keys' => array_values($missing),
            'last_test_result' => $testResult,
            'versions' => array_map(self::version(...), $versions),
            'created_by' => self::nullableInt($row['created_by'] ?? null),
            'created_at' => self::nullableString($row['created_at'] ?? null),
            'updated_at' => self::nullableString($row['updated_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function step(array $row): array
    {
        return [
            'step_index' => self::int($row['step_index'] ?? 0),
            'action_key' => self::string($row['action_key'] ?? ''),
            'params' => self::document($row['params'] ?? null),
            'params_version' => self::int($row['params_version'] ?? 1),
            'guard_conditions' => self::document($row['guard_conditions'] ?? null),
            'guard_conditions_version' => self::int($row['guard_conditions_version'] ?? 1),
            'delay_anchor_key' => self::nullableString($row['delay_anchor_key'] ?? null),
            'delay' => self::document($row['delay'] ?? null),
            'delay_version' => self::int($row['delay_version'] ?? 1),
            'retry_policy_key' => self::string($row['retry_policy_key'] ?? ''),
            'failure_policy_key' => self::string($row['failure_policy_key'] ?? ''),
            'requires_approval_before_effect' => self::bool($row['requires_approval_before_effect'] ?? false),
            'produces_review_item' => self::bool($row['produces_review_item'] ?? false),
            'armed_prompt_ref' => self::nullableString($row['armed_prompt_ref'] ?? null),
            'owner_user_id' => self::nullableInt($row['owner_user_id'] ?? null),
            'sentence' => null,
            'missing' => (bool) ($row['_missing'] ?? false),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function version(array $row): array
    {
        return [
            'version' => self::int($row['version'] ?? 1),
            'active' => ($row['activated_at'] ?? null) !== null && ($row['retired_at'] ?? null) === null,
            'payload_version' => self::int($row['payload_version'] ?? 1),
            'created_by' => self::nullableInt($row['created_by'] ?? null),
            'activated_at' => self::nullableString($row['activated_at'] ?? null),
            'retired_at' => self::nullableString($row['retired_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function run(array $row): array
    {
        return [
            'id' => self::int($row['id'] ?? 0),
            'rule_id' => self::int($row['rule_id'] ?? 0),
            'rule_version' => self::int($row['rule_version'] ?? 1),
            'trigger_key' => self::string($row['trigger_key'] ?? ''),
            'event_ref' => self::string($row['event_ref'] ?? ''),
            'occurrence_key' => self::string($row['occurrence_key'] ?? ''),
            'subject_type' => self::nullableString($row['subject_type'] ?? null),
            'subject_id' => self::nullableInt($row['subject_id'] ?? null),
            'actor_user_id' => self::nullableInt($row['actor_user_id'] ?? null),
            'dry_run' => self::bool($row['dry_run'] ?? false),
            'state' => self::string($row['state'] ?? 'pending_fanout'),
            'cascade_depth' => self::int($row['cascade_depth'] ?? 0),
            'rule_chain' => self::nullableString($row['rule_chain'] ?? null),
            'counters' => [
                'matched' => self::int($row['matched_count'] ?? 0),
                'executed' => self::int($row['executed_count'] ?? 0),
                'skipped' => self::int($row['skipped_count'] ?? 0),
                'failed' => self::int($row['failed_count'] ?? 0),
            ],
            'started_at' => self::nullableString($row['started_at'] ?? null),
            'finished_at' => self::nullableString($row['finished_at'] ?? null),
            'created_at' => self::nullableString($row['created_at'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function actionLogEntry(array $row): array
    {
        return [
            'id' => self::int($row['id'] ?? 0),
            'queue_id' => self::nullableInt($row['queue_id'] ?? null),
            'run_id' => self::nullableInt($row['run_id'] ?? null),
            'rule_id' => self::int($row['rule_id'] ?? 0),
            'rule_version' => self::int($row['rule_version'] ?? 1),
            'step_index' => self::int($row['step_index'] ?? 0),
            'action_key' => self::string($row['action_key'] ?? ''),
            'subject_type' => self::nullableString($row['subject_type'] ?? null),
            'subject_id' => self::nullableInt($row['subject_id'] ?? null),
            'actor_user_id' => self::nullableInt($row['actor_user_id'] ?? null),
            'idem_key' => self::string($row['idem_key'] ?? ''),
            'success' => self::bool($row['success'] ?? false),
            'before_state' => self::document($row['before_state'] ?? null),
            'after_state' => self::document($row['after_state'] ?? null),
            'state_version' => self::int($row['state_version'] ?? 1),
            'error_code' => self::nullableString($row['error_code'] ?? null),
            'error_message' => self::nullableString($row['error_message'] ?? null),
            'ai_run_id' => self::nullableInt($row['ai_run_id'] ?? null),
            'executed_at' => self::string($row['executed_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function test(array $row): array
    {
        return [
            'id' => self::int($row['id'] ?? 0),
            'rule_id' => self::int($row['rule_id'] ?? 0),
            'rule_version' => self::int($row['rule_version'] ?? 1),
            'name' => self::string($row['name'] ?? ''),
            'given' => self::document($row['given'] ?? null) ?? [],
            'given_version' => self::int($row['given_version'] ?? 1),
            'changed' => self::document($row['changed'] ?? null) ?? [],
            'changed_version' => self::int($row['changed_version'] ?? 1),
            'expectations' => self::document($row['expectations'] ?? null) ?? [],
            'expectations_version' => self::int($row['expectations_version'] ?? 1),
            'last_run_at' => self::nullableString($row['last_run_at'] ?? null),
            'last_result' => self::nullableString($row['last_result'] ?? null),
            'last_detail' => self::nullableString($row['last_detail'] ?? null),
            'created_by' => self::nullableInt($row['created_by'] ?? null),
        ];
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    public static function page(array $items, int $total, int $page, int $perPage): array
    {
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /**
     * A stored JSON column, unwrapped from its StoredShape envelope.
     *
     * The version travels in its own mirrored column, so the client never has
     * to know the reserved key exists; leaving the envelope on the wire would
     * publish an implementation detail every client would then copy.
     *
     * @return array<string, mixed>|null
     */
    private static function document(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (\is_string($value)) {
            $decoded = json_decode($value, true);
            $value = \is_array($decoded) ? $decoded : null;
        }

        if (!\is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return \array_key_exists(StoredShape::VERSION_KEY, $value) ? StoredShape::bodyOf($value) : $value;
    }

    private static function int(mixed $value): int
    {
        return \is_numeric($value) ? (int) $value : 0;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : self::int($value);
    }

    private static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : self::string($value);
    }

    private static function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
