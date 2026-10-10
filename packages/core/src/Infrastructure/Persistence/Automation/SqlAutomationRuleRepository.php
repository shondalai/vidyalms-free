<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTreeCodec;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRule;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRuleState;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStepDefinition;
use Shondalai\VidyaLMS\Core\Domain\Automation\ReentryMode;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The rule, its numbered versions and its steps.
 *
 * Three tables and one repository, because the three are written together or
 * not at all: activating a new version deactivates the previous one, and the
 * steps are copied per version so an armed rule's params cannot be edited
 * afterwards with nothing to detect the edit against.
 *
 * A run already executing keeps the version it started on, which is why
 * automation_queue carries rule_version and why stepsFor() takes one.
 */
final class SqlAutomationRuleRepository
{
    public const TABLE = 'automation_rules';
    public const VERSION_TABLE = 'automation_rule_versions';
    public const ACTION_TABLE = 'automation_rule_actions';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * Writes a rule at a new version, copies its steps under that version and
     * stores the authored document byte for byte.
     *
     * The version payload is mediumtext and not json, for exactly the reason
     * idempotency_keys.outcome is: it holds the document a running instance is
     * still executing against and it must come back unchanged. MySQL's JSON
     * type stores a normalised binary form and returns keys in its own order.
     * Nothing queries into the column.
     */
    public function save(AutomationRule $rule, int $createdBy): int
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);
        $row = $this->values($rule);
        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        $row['created_by'] = $rule->createdBy ?? $createdBy;

        $existing = $rule->id !== null
            ? $this->row($rule->id)
            : $this->database->selectOne(
                Query::from(self::TABLE)
                    ->where('rule_key', '=', $rule->ruleKey)
                    ->where('version', '=', $rule->version),
            );

        if ($existing === null) {
            $id = $this->database->insert(self::TABLE, $row);
        } else {
            $id = (int) $existing['id'];
            unset($row['created_at'], $row['created_by']);
            $this->database->update(self::TABLE, $row, ['id' => $id]);
        }

        $this->writeVersion($id, $rule, $row['created_by'] ?? $createdBy, $now);
        $this->writeSteps($id, $rule);

        return $id;
    }

    /**
     * Exactly one version is active, and activating one retires the rest.
     *
     * Copied from Salesforce without amendment, and it is the reason the
     * unique is (rule_key, version) rather than rule_key alone.
     */
    public function activateVersion(int $ruleId, int $version): void
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        foreach (
            $this->database->selectAll(
                Query::from(self::VERSION_TABLE)->select(['id', 'version'])->where('rule_id', '=', $ruleId),
            ) as $row
        ) {
            $isTarget = (int) $row['version'] === $version;

            $this->database->update(self::VERSION_TABLE, [
                'activated_at' => $isTarget ? $now : null,
                'retired_at' => $isTarget ? null : $now,
                'updated_at' => $now,
            ], ['id' => (int) $row['id']]);
        }

        $this->database->update(self::TABLE, [
            'version' => $version,
            'active' => 1,
            'updated_at' => $now,
        ], ['id' => $ruleId]);
    }

    public function find(int $id): ?AutomationRule
    {
        $row = $this->row($id);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByKey(string $ruleKey): ?AutomationRule
    {
        $row = $this->database->selectOne(
            Query::from(self::TABLE)->where('rule_key', '=', $ruleKey)->orderBy('version', Query::SORT_DESC),
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * The fan-out query's only predicate, in the order the design publishes.
     *
     * Several rules on one trigger resolve by run_order with creation time as
     * the deterministic tie-break, and the resolved order is what the admin
     * shows. The alternative is a support burden of non-reproducible orderings
     * nobody can debug from a log.
     *
     * @return list<AutomationRule>
     */
    public function enabledForTrigger(string $triggerKey): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('trigger_key', '=', $triggerKey)
                ->where('state', '=', AutomationRuleState::Enabled->value)
                ->where('active', '=', 1)
                ->orderBy('run_order')
                ->orderBy('created_at')
                ->orderBy('id'),
        );

        return array_map(fn (array $row): AutomationRule => $this->hydrate($row), $rows);
    }

    /**
     * Every rule an operator can see, newest first, for the rules screen.
     *
     * @return list<AutomationRule>
     */
    public function all(int $limit = 200): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)->orderBy('id', Query::SORT_DESC)->limit($limit),
        );

        return array_map(fn (array $row): AutomationRule => $this->hydrate($row), $rows);
    }

    /**
     * A registry miss is a rule-level condition and never a step failure, so
     * this is the only writer of the third state.
     */
    public function markState(int $ruleId, AutomationRuleState $state): void
    {
        $this->database->update(self::TABLE, [
            'state' => $state->value,
            'updated_at' => $this->clock->now()->format(self::DATETIME_FORMAT),
        ], ['id' => $ruleId]);
    }

    public function arm(int $ruleId, int $armedBy, string $armedDigest): void
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->database->update(self::TABLE, [
            'armed_by' => $armedBy,
            'armed_at' => $now,
            'armed_digest' => $armedDigest,
            'state' => AutomationRuleState::Enabled->value,
            'active' => 1,
            'updated_at' => $now,
        ], ['id' => $ruleId]);
    }

    /**
     * Disarming clears the arming triple as well as the state.
     *
     * Any edit to a rule carrying an action that declares
     * RequiresApprovalBeforeEffect or DeclaresCost creates a new version and
     * clears the arming, so the rule is inert until a person arms it again.
     * That rule is enforced by the use case; this is the writer it uses.
     */
    public function disarm(int $ruleId): void
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->database->update(self::TABLE, [
            'armed_by' => null,
            'armed_at' => null,
            'armed_digest' => null,
            'state' => AutomationRuleState::Disabled->value,
            'updated_at' => $now,
        ], ['id' => $ruleId]);
    }

    public function stampDryRun(int $ruleId): void
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->database->update(self::TABLE, [
            'last_dry_run_at' => $now,
            'updated_at' => $now,
        ], ['id' => $ruleId]);
    }

    /** One of queued_count, executed_count, suppressed_count, failed_count. */
    public function incrementCounter(int $ruleId, string $column, int $by = 1): void
    {
        if (!\in_array($column, ['queued_count', 'executed_count', 'suppressed_count', 'failed_count'], true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('"%s" is not an automation rule counter.', $column));
        }

        $row = $this->row($ruleId);

        if ($row === null) {
            return;
        }

        $this->database->update(self::TABLE, [
            $column => (int) ($row[$column] ?? 0) + $by,
            'updated_at' => $this->clock->now()->format(self::DATETIME_FORMAT),
        ], ['id' => $ruleId]);
    }

    /**
     * The steps of one version, in step order.
     *
     * @return list<AutomationStepDefinition>
     */
    public function stepsFor(int $ruleId, int $version): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::ACTION_TABLE)
                ->where('rule_id', '=', $ruleId)
                ->where('rule_version', '=', $version)
                ->orderBy('step_index'),
        );

        return array_map(static function (array $row): AutomationStepDefinition {
            $params = self::decodeShape($row['params'] ?? null);
            // bodyOf strips the envelope and is a no-op on a document written
            // before the envelope existed, so a stored row of either shape
            // reads back the same tree.
            $stored = self::decodeArray($row['guard_conditions'] ?? null);
            $guard = $stored === null ? null : StoredShape::bodyOf($stored);

            return new AutomationStepDefinition(
                (int) $row['step_index'],
                (string) $row['action_key'],
                $params,
                (int) ($row['params_version'] ?? 1),
                $guard === null ? null : AvailabilityTreeCodec::decode($guard),
                $row['delay_anchor_key'] === null ? null : (string) $row['delay_anchor_key'],
                self::decodeShape($row['delay'] ?? null),
                (string) ($row['retry_policy_key'] ?? 'external_default'),
                (string) ($row['failure_policy_key'] ?? 'park'),
                (int) ($row['requires_approval_before_effect'] ?? 0) === 1,
                (int) ($row['produces_review_item'] ?? 0) === 1,
                $row['armed_prompt_ref'] === null ? null : (string) $row['armed_prompt_ref'],
                $row['owner_user_id'] === null ? null : (int) $row['owner_user_id'],
                (int) ($row['delay_version'] ?? 1),
            );
        }, $rows);
    }

    /**
     * The authored document as it was stored, byte for byte.
     */
    public function versionPayload(int $ruleId, int $version): ?string
    {
        $row = $this->database->selectOne(
            Query::from(self::VERSION_TABLE)
                ->select(['payload'])
                ->where('rule_id', '=', $ruleId)
                ->where('version', '=', $version),
        );

        return $row === null ? null : (string) $row['payload'];
    }

    /** @return array<string, mixed>|null */
    private function row(int $id): ?array
    {
        return $this->database->selectOne(Query::from(self::TABLE)->where('id', '=', $id));
    }

    private function writeVersion(int $ruleId, AutomationRule $rule, int $createdBy, string $now): void
    {
        $payload = json_encode(StoredShape::wrap([
            'rule_key' => $rule->ruleKey,
            'title' => $rule->title,
            'trigger_key' => $rule->triggerKey,
            'conditions' => $rule->conditions === null ? null : AvailabilityTreeCodec::encode($rule->conditions),
            'steps' => array_map(static fn (AutomationStepDefinition $step): array => [
                'step_index' => $step->stepIndex,
                'action_key' => $step->actionKey,
                'params' => $step->params,
                'params_version' => $step->paramsVersion,
                'retry_policy_key' => $step->retryPolicyKey,
                'failure_policy_key' => $step->failurePolicyKey,
                'delay_anchor_key' => $step->delayAnchorKey,
                'delay' => $step->delay,
            ], $rule->steps),
        ], 1), \JSON_THROW_ON_ERROR);

        $existing = $this->database->selectOne(
            Query::from(self::VERSION_TABLE)
                ->select(['id'])
                ->where('rule_id', '=', $ruleId)
                ->where('version', '=', $rule->version),
        );

        if ($existing === null) {
            $this->database->insert(self::VERSION_TABLE, [
                'rule_id' => $ruleId,
                'version' => $rule->version,
                'payload' => $payload,
                'payload_version' => 1,
                'created_by' => $createdBy,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return;
        }

        $this->database->update(self::VERSION_TABLE, [
            'payload' => $payload,
            'payload_version' => 1,
            'updated_at' => $now,
        ], ['id' => (int) $existing['id']]);
    }

    private function writeSteps(int $ruleId, AutomationRule $rule): void
    {
        $this->database->delete(self::ACTION_TABLE, ['rule_id' => $ruleId, 'rule_version' => $rule->version]);

        foreach ($rule->steps as $step) {
            $guard = $step->guardConditions === null ? null : AvailabilityTreeCodec::encode($step->guardConditions);

            $this->database->insert(self::ACTION_TABLE, [
                'rule_id' => $ruleId,
                'rule_version' => $rule->version,
                'step_index' => $step->stepIndex,
                'action_key' => $step->actionKey,
                'params' => $step->params === []
                    ? null
                    : json_encode(StoredShape::wrap($step->params, $step->paramsVersion), \JSON_THROW_ON_ERROR),
                'params_version' => $step->paramsVersion,
                // Wrapped, so the mirrored _version column describes something
                // real. Both condition documents used to be written as a bare
                // json_encode with their version columns hardcoded to 1, so
                // StoredShape::versionOf reported UNVERSIONED for the two
                // documents whose columns claimed a version.
                'guard_conditions' => $guard === null
                    ? null
                    : json_encode(StoredShape::wrap($guard, 1), \JSON_THROW_ON_ERROR),
                'guard_conditions_version' => 1,
                'delay_anchor_key' => $step->delayAnchorKey,
                // The delay payload's version is the anchor provider's, not
                // the core's: an anchor package shipping a second payload
                // shape had nothing to key a migration against while this was
                // the literal 1 in two places.
                'delay' => $step->delay === []
                    ? null
                    : json_encode(StoredShape::wrap($step->delay, $step->delayVersion), \JSON_THROW_ON_ERROR),
                'delay_version' => $step->delayVersion,
                'retry_policy_key' => $step->retryPolicyKey,
                'failure_policy_key' => $step->failurePolicyKey,
                'requires_approval_before_effect' => $step->requiresApprovalBeforeEffect ? 1 : 0,
                'produces_review_item' => $step->producesReviewItem ? 1 : 0,
                'armed_prompt_ref' => $step->armedPromptRef,
                'owner_user_id' => $step->ownerUserId,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AutomationRule
    {
        $id = (int) $row['id'];
        $version = (int) ($row['version'] ?? 1);
        $stored = self::decodeArray($row['conditions'] ?? null);
        $conditions = $stored === null ? null : StoredShape::bodyOf($stored);

        return new AutomationRule(
            $id,
            (string) $row['rule_key'],
            (string) ($row['title'] ?? ''),
            (string) $row['trigger_key'],
            $conditions === null ? null : AvailabilityTreeCodec::decode($conditions),
            $this->stepsFor($id, $version),
            ReentryMode::tryFrom((string) ($row['reentry'] ?? 'never')) ?? ReentryMode::Never,
            $row['times_per_subject'] === null ? null : (int) $row['times_per_subject'],
            $row['total_completions'] === null ? null : (int) $row['total_completions'],
            (int) ($row['cooldown_seconds'] ?? 300),
            (int) ($row['run_order'] ?? 100),
            $version,
            (int) ($row['active'] ?? 0) === 1,
            AutomationRuleState::tryFrom((string) ($row['state'] ?? 'disabled')) ?? AutomationRuleState::Disabled,
            (string) ($row['subject_binding'] ?? 'learner'),
            (string) ($row['scope_type'] ?? 'site'),
            (int) ($row['scope_id'] ?? 0),
            $row['course_id'] === null ? null : (int) $row['course_id'],
            $row['cohort_id'] === null ? null : (int) $row['cohort_id'],
            $row['organisation_id'] === null ? null : (int) $row['organisation_id'],
            (string) ($row['schedule_kind'] ?? 'immediate'),
            $row['anchor_key'] === null ? null : (string) $row['anchor_key'],
            $row['armed_by'] === null ? null : (int) $row['armed_by'],
            self::instant($row['armed_at'] ?? null),
            $row['armed_digest'] === null ? null : (string) $row['armed_digest'],
            $row['max_ai_runs_per_occurrence'] === null ? null : (int) $row['max_ai_runs_per_occurrence'],
            $row['max_ai_runs_per_arming'] === null ? null : (int) $row['max_ai_runs_per_arming'],
            $row['created_by'] === null ? null : (int) $row['created_by'],
            self::instant($row['last_dry_run_at'] ?? null),
            (int) ($row['queued_count'] ?? 0),
            (int) ($row['executed_count'] ?? 0),
            (int) ($row['suppressed_count'] ?? 0),
            (int) ($row['failed_count'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    private function values(AutomationRule $rule): array
    {
        $conditions = $rule->conditions === null ? null : AvailabilityTreeCodec::encode($rule->conditions);

        return [
            'rule_key' => $rule->ruleKey,
            'title' => $rule->title,
            'trigger_key' => $rule->triggerKey,
            'definition' => null,
            'definition_version' => 1,
            // Wrapped for the reason the guard tree is: a _version column
            // mirroring a document with no envelope describes nothing.
            'conditions' => $conditions === null
                ? null
                : json_encode(StoredShape::wrap($conditions, 1), \JSON_THROW_ON_ERROR),
            'conditions_version' => 1,
            'schedule_kind' => $rule->scheduleKind,
            'anchor_key' => $rule->anchorKey,
            'schedule' => null,
            'schedule_version' => 1,
            'subject_binding' => $rule->subjectBinding,
            'reentry' => $rule->reentry->value,
            'times_per_subject' => $rule->timesPerSubject,
            'total_completions' => $rule->totalCompletions,
            'cooldown_seconds' => $rule->cooldownSeconds,
            'max_ai_runs_per_occurrence' => $rule->maxAiRunsPerOccurrence,
            'max_ai_runs_per_arming' => $rule->maxAiRunsPerArming,
            'run_order' => $rule->runOrder,
            'version' => $rule->version,
            'active' => $rule->active ? 1 : 0,
            'scope_type' => $rule->scopeType,
            'scope_id' => $rule->scopeId,
            'course_id' => $rule->courseId,
            'cohort_id' => $rule->cohortId,
            'organisation_id' => $rule->organisationId,
            'armed_by' => $rule->armedBy,
            'armed_at' => $rule->armedAt?->format(self::DATETIME_FORMAT),
            'armed_digest' => $rule->armedDigest,
            'state' => $rule->state->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeShape(mixed $value): array
    {
        $decoded = self::decodeArray($value);

        return $decoded === null ? [] : StoredShape::bodyOf($decoded);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeArray(mixed $value): ?array
    {
        if (\is_array($value)) {
            return $value;
        }

        if (!\is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : null;
    }

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) && $value !== ''
            ? new \DateTimeImmutable($value, new \DateTimeZone('UTC'))
            : null;
    }
}
