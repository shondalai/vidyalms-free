<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTreeCodec;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRule;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRuleState;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationStepDefinition;
use Shondalai\VidyaLMS\Core\Domain\Automation\ReentryMode;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\Presenter\AutomationPresenter;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationQueueRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationReadModel;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\FailurePolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Automation\RequiresApprovalBeforeEffect;
use Shondalai\VidyaLMS\Core\Registry\Automation\RetryPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\EventTriggerInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\SweepTriggerInterface;

/**
 * The wire side of rule authoring: the allowlist, the optimistic-concurrency
 * check, the paging and the presentation.
 *
 * It owns no rule of the engine. Every save, arming and disarming goes through
 * ManageAutomationRules, so the save-time refusals live in one place and this
 * class cannot drift into a second, gentler copy of them. What it does own is
 * the boundary: an authored JSON document is not a domain object, and turning
 * one into the other is anti-corruption work that belongs at the edge rather
 * than inside a use case that should never have seen a request body.
 *
 * Three things here are refusals of its own, and each is about the request
 * rather than about the rule. A field the operation does not name is refused
 * by name rather than dropped with a 200. An expected_version that no longer
 * matches is a conflict rather than a silent overwrite of somebody else's
 * edit. And arming is stated rather than inferred, so the audit record names
 * an act a person actually took.
 */
final class AutomationRuleAdmin
{
    /** Arming is an explicit act, so the request says so rather than implying it. */
    public const ACKNOWLEDGEMENT = 'this_rule_may_act_on_people';

    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    /** @var list<string> */
    private const RULE_FIELDS = [
        'rule_key',
        'title',
        'trigger_key',
        'definition',
        'definition_version',
        'conditions',
        'conditions_version',
        'schedule_kind',
        'anchor_key',
        'schedule',
        'schedule_version',
        'subject_binding',
        'reentry',
        'times_per_subject',
        'total_completions',
        'cooldown_seconds',
        'run_order',
        'scope_type',
        'scope_id',
        'course_id',
        'cohort_id',
        'organisation_id',
        'max_ai_runs_per_occurrence',
        'max_ai_runs_per_arming',
        'actions',
        'expected_version',
    ];

    /** @var list<string> */
    private const STEP_FIELDS = [
        'action_key',
        'params',
        'params_version',
        'guard_conditions',
        'guard_conditions_version',
        'delay_anchor_key',
        'delay',
        'delay_version',
        'retry_policy_key',
        'failure_policy_key',
        'owner_user_id',
    ];

    public function __construct(
        private readonly ManageAutomationRules $rules,
        private readonly SqlAutomationReadModel $reads,
        private readonly SqlAutomationQueueRepository $queue,
        private readonly NotificationTriggerRegistry $triggers,
        private readonly AutomationActionRegistry $actions,
        private readonly RetryPolicyRegistry $retryPolicies,
        private readonly FailurePolicyRegistry $failurePolicies,
        private readonly AutomationPresenter $presenter,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function list(
        Principal $principal,
        ?string $triggerKey,
        ?string $state,
        ?string $actionKey,
        ?string $search,
        int $page,
        int $perPage,
    ): array {
        $this->requireManage($principal);
        $perPage = $this->perPage($perPage);
        $page = max(1, $page);
        $result = $this->reads->rulePage($triggerKey, $state, $actionKey, $search, $perPage, ($page - 1) * $perPage);

        return AutomationPresenter::page(
            array_map(fn (array $row): array => $this->present($row), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Principal $principal, int $id): array
    {
        $this->requireManage($principal);

        return $this->present($this->rowOrMiss($id));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function save(Principal $principal, ?int $id, array $body): array
    {
        $this->requireManage($principal);
        AutomationInput::only($body, self::RULE_FIELDS);

        $existing = $id === null ? null : $this->rowOrMiss($id);

        if ($existing === null && \array_key_exists('expected_version', $body)) {
            throw ValidationException::forField(
                'expected_version',
                'A rule that does not exist yet has no version to match.',
            );
        }

        if ($existing !== null) {
            $this->requireExpectedVersion($body, (int) $existing['version']);
        }

        $saved = $this->rules->save($principal, $this->assemble($body, $existing));

        return $this->present($this->rowOrMiss($saved->id ?? ($existing === null ? 0 : (int) $existing['id'])));
    }

    /**
     * Archiving stops the rule and cancels what it has queued, and keeps every
     * run and every action-log row it ever wrote.
     *
     * The audit outlives the rule that produced it: a run names the rule id and
     * the version it executed under, so deleting the rule leaves a history
     * nobody can read.
     *
     * @return array<string, mixed>
     */
    public function archive(Principal $principal, int $id): array
    {
        $this->requireManage($principal);
        $row = $this->rowOrMiss($id);
        $ruleId = (int) $row['id'];
        $cancelled = $this->queue->cancelForRule($ruleId);
        $this->rules->disarm($principal, $ruleId);

        return [
            'id' => $ruleId,
            'state' => 'archived',
            'cancelled_queue_rows' => $cancelled,
            'runs_retained' => $this->reads->runPage($ruleId, null, null, 1, 0)['total'],
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function arm(Principal $principal, int $id, array $body): array
    {
        $this->requireManage($principal);
        AutomationInput::only($body, ['expected_version', 'acknowledgement']);
        $row = $this->rowOrMiss($id);
        $this->requireExpectedVersion($body, (int) $row['version'], true);

        if (($body['acknowledgement'] ?? null) !== self::ACKNOWLEDGEMENT) {
            throw ValidationException::forField(
                'acknowledgement',
                'Arming is an explicit act and is stated rather than inferred.',
            );
        }

        $this->rules->arm($principal, $id);

        return $this->present($this->rowOrMiss($id));
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function disarm(Principal $principal, int $id, array $body): array
    {
        $this->requireManage($principal);
        AutomationInput::only($body, ['expected_version']);
        $row = $this->rowOrMiss($id);
        $this->requireExpectedVersion($body, (int) $row['version'], true);

        $this->queue->cancelForRule($id);
        $this->rules->disarm($principal, $id);

        return $this->present($this->rowOrMiss($id));
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $id = (int) $row['id'];
        $version = (int) $row['version'];
        $steps = $this->reads->steps($id, $version);
        $missing = [];

        foreach ($steps as $index => $step) {
            $stepMissing = false;

            foreach ($this->registries() as $column => $registry) {
                $key = (string) ($step[$column] ?? '');

                if ($key !== '' && !$registry->has($key)) {
                    $missing[] = $key;
                    $stepMissing = $stepMissing || $column === 'action_key';
                }
            }

            $steps[$index]['_missing'] = $stepMissing;
        }

        return $this->presenter->rule(
            $row,
            $steps,
            $this->reads->versions($id),
            array_values(array_unique($missing)),
            $this->reads->worstTestResult($id),
        );
    }

    /**
     * The three stored provider keys of one step, each with the registry that
     * answers it. A key no registry answers is what makes the whole rule
     * degraded rather than the step failing on its four hundredth subject.
     *
     * @return array<string, AutomationActionRegistry|RetryPolicyRegistry|FailurePolicyRegistry>
     */
    private function registries(): array
    {
        return [
            'action_key' => $this->actions,
            'retry_policy_key' => $this->retryPolicies,
            'failure_policy_key' => $this->failurePolicies,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOrMiss(int $id): array
    {
        $row = $this->reads->rule($id);

        if ($row === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('AutomationRule', $id);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requireExpectedVersion(array $body, int $current, bool $required = false): void
    {
        $expected = $body['expected_version'] ?? null;

        if ($expected === null) {
            if ($required) {
                throw ValidationException::forField('expected_version', 'The version you last read is required.');
            }

            return;
        }

        if (!\is_int($expected)) {
            throw ValidationException::forField('expected_version', 'Expected a positive integer.');
        }

        if ($expected !== $current) {
            throw new ConflictException(
                'This rule has moved on since you read it. Reload it and apply your change again.',
            );
        }
    }

    /**
     * The authored document as a domain rule.
     *
     * An absent field keeps what is stored, which is what makes this a partial
     * update; a present one replaces it. The action list is replaced whole
     * because its order is the semantics rather than a display preference, and
     * merging two orderings would produce a third nobody authored.
     *
     * @param array<string, mixed>      $body
     * @param array<string, mixed>|null $existing
     */
    private function assemble(array $body, ?array $existing): AutomationRule
    {
        $title = $body['title'] ?? ($existing['title'] ?? null);

        if (!\is_string($title) || trim($title) === '') {
            throw ValidationException::forField('title', 'A rule needs a title a person can read.');
        }

        $triggerKey = $body['trigger_key'] ?? ($existing['trigger_key'] ?? null);

        if (!\is_string($triggerKey) || $triggerKey === '') {
            throw ValidationException::forField('trigger_key', 'A rule needs a trigger.');
        }

        $this->refuseUnusableTrigger($triggerKey);

        $ruleKey = $body['rule_key'] ?? ($existing['rule_key'] ?? null);

        if (!\is_string($ruleKey) || $ruleKey === '') {
            $ruleKey = self::slug($title);
        }

        $conditions = \array_key_exists('conditions', $body)
            ? (\is_array($body['conditions']) ? $body['conditions'] : null)
            : self::decodeColumn($existing['conditions'] ?? null);

        $reentry = ReentryMode::tryFrom(self::word($body, $existing, 'reentry', 'never'));

        if ($reentry === null) {
            throw ValidationException::forField('reentry', 'Unknown re-entry mode.');
        }

        $state = $existing === null
            ? AutomationRuleState::Disabled
            : (AutomationRuleState::tryFrom((string) $existing['state']) ?? AutomationRuleState::Disabled);

        return new AutomationRule(
            id: $existing === null ? null : (int) $existing['id'],
            ruleKey: $ruleKey,
            title: trim($title),
            triggerKey: $triggerKey,
            conditions: AvailabilityTreeCodec::decode($conditions),
            steps: $this->readSteps($body, $existing),
            reentry: $reentry,
            timesPerSubject: self::nullableCount($body, $existing, 'times_per_subject'),
            totalCompletions: self::nullableCount($body, $existing, 'total_completions'),
            cooldownSeconds: self::count($body, $existing, 'cooldown_seconds', 300),
            runOrder: self::count($body, $existing, 'run_order', 100),
            // The version this edit is against, and never the next one. The
            // bump belongs to ManageAutomationRules, so an importer and a
            // course copy get it too; computing it here made the invariant
            // hold only on the wire path.
            version: $existing === null ? 1 : (int) $existing['version'],
            active: $existing !== null && (int) ($existing['active'] ?? 0) === 1,
            state: $state,
            subjectBinding: self::word($body, $existing, 'subject_binding', 'learner'),
            scopeType: self::word($body, $existing, 'scope_type', 'site'),
            scopeId: self::count($body, $existing, 'scope_id', 0),
            courseId: self::nullableCount($body, $existing, 'course_id'),
            cohortId: self::nullableCount($body, $existing, 'cohort_id'),
            organisationId: self::nullableCount($body, $existing, 'organisation_id'),
            scheduleKind: self::word($body, $existing, 'schedule_kind', 'immediate'),
            anchorKey: \is_string($body['anchor_key'] ?? null) && $body['anchor_key'] !== ''
                ? $body['anchor_key']
                : (\is_string($existing['anchor_key'] ?? null) ? $existing['anchor_key'] : null),
            // The whole triple is carried through rather than half of it. It
            // used to pass armed_by and omit armed_at and armed_digest, and
            // the repository writes all four, so an edit left armed_by set
            // with no instant and no digest: a state the design says should be
            // impossible. The use case clears all three together.
            armedBy: $existing === null ? null : self::nullableCount([], $existing, 'armed_by'),
            armedAt: $existing === null ? null : self::instant($existing['armed_at'] ?? null),
            armedDigest: $existing === null
                ? null
                : (\is_string($existing['armed_digest'] ?? null) ? $existing['armed_digest'] : null),
            maxAiRunsPerOccurrence: self::nullableCount($body, $existing, 'max_ai_runs_per_occurrence'),
            maxAiRunsPerArming: self::nullableCount($body, $existing, 'max_ai_runs_per_arming'),
            createdBy: $existing === null ? null : self::nullableCount([], $existing, 'created_by'),
        );
    }

    /**
     * @param array<string, mixed>      $body
     * @param array<string, mixed>|null $existing
     *
     * @return list<AutomationStepDefinition>
     */
    private function readSteps(array $body, ?array $existing): array
    {
        if (!\array_key_exists('actions', $body)) {
            if ($existing === null) {
                throw ValidationException::forField('actions', 'A rule needs at least one action.');
            }

            return array_map(
                self::stepFromRow(...),
                $this->reads->steps((int) $existing['id'], (int) $existing['version']),
            );
        }

        $authored = $body['actions'];

        if (!\is_array($authored) || $authored === [] || !array_is_list($authored)) {
            throw ValidationException::forField('actions', 'A rule needs at least one action, in order.');
        }

        $steps = [];

        foreach ($authored as $index => $entry) {
            if (!\is_array($entry)) {
                throw ValidationException::forField('actions', 'Every action is an object.');
            }

            /** @var array<string, mixed> $entry */
            AutomationInput::only($entry, self::STEP_FIELDS);
            $actionKey = $entry['action_key'] ?? null;

            if (!\is_string($actionKey) || $actionKey === '') {
                throw ValidationException::forField('actions', 'Every action names a registered action key.');
            }

            $steps[] = new AutomationStepDefinition(
                stepIndex: $index,
                actionKey: $actionKey,
                params: \is_array($entry['params'] ?? null) ? $entry['params'] : [],
                paramsVersion: \is_int($entry['params_version'] ?? null) ? $entry['params_version'] : 1,
                guardConditions: \is_array($entry['guard_conditions'] ?? null)
                    ? AvailabilityTreeCodec::decode($entry['guard_conditions'])
                    : null,
                delayAnchorKey: self::text($entry, 'delay_anchor_key'),
                delay: \is_array($entry['delay'] ?? null) ? $entry['delay'] : [],
                retryPolicyKey: self::text($entry, 'retry_policy_key') ?? 'external_default',
                failurePolicyKey: self::text($entry, 'failure_policy_key') ?? 'park',
                requiresApprovalBeforeEffect: $this->needsApproval($actionKey),
                producesReviewItem: false,
                ownerUserId: \is_int($entry['owner_user_id'] ?? null) ? $entry['owner_user_id'] : null,
                // The delay payload's version, which belongs to the anchor
                // provider. It was in the allowlist and read by nothing, so
                // the repository wrote the literal 1 and the one
                // provider-shaped document StoredShape exists for was the one
                // it did not version.
                delayVersion: \is_int($entry['delay_version'] ?? null) && $entry['delay_version'] > 0
                    ? $entry['delay_version']
                    : 1,
            );
        }

        return $steps;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function stepFromRow(array $row): AutomationStepDefinition
    {
        $params = $row['params'] ?? null;

        if (\is_string($params)) {
            $decoded = json_decode($params, true);
            $params = \is_array($decoded) ? $decoded : [];
        }

        return new AutomationStepDefinition(
            stepIndex: (int) ($row['step_index'] ?? 0),
            actionKey: (string) ($row['action_key'] ?? ''),
            params: \is_array($params) ? $params : [],
            paramsVersion: (int) ($row['params_version'] ?? 1),
            delayAnchorKey: self::text($row, 'delay_anchor_key'),
            retryPolicyKey: (string) ($row['retry_policy_key'] ?? 'external_default'),
            failurePolicyKey: (string) ($row['failure_policy_key'] ?? 'park'),
            requiresApprovalBeforeEffect: (bool) ($row['requires_approval_before_effect'] ?? false),
            producesReviewItem: (bool) ($row['produces_review_item'] ?? false),
            ownerUserId: ($row['owner_user_id'] ?? null) === null ? null : (int) $row['owner_user_id'],
            delayVersion: (int) ($row['delay_version'] ?? 1),
        );
    }

    /**
     * A trigger this site cannot fan out from is refused at save, and it is the
     * one registry miss that is refused rather than recorded.
     *
     * A missing action key leaves the rule degraded on purpose, because the
     * package that answers it can be reinstalled and the authored intent
     * survives. A trigger is different in kind: it is the rule's only entry
     * point, so a rule on an unresolvable trigger has no path to a run at all,
     * and one that resolves to neither an event trigger nor a sweep trigger
     * declares no subject for the fan-out to act on. The catalogue offers only
     * the triggers that pass this test, so a builder can never author one.
     *
     * **AutomationRuleValidator is the authority and this is the field
     * mapping.** The check lived here alone, so a package, an importer or a
     * course copy saved and armed a rule on a trigger nobody answers and it
     * failed only when it fired, which is the failure the validator's own
     * docblock says save time exists to prevent. It stays here because a
     * refusal about one named field is a 422 pointing at that field, which a
     * rule-level refusal cannot be.
     */
    private function refuseUnusableTrigger(string $triggerKey): void
    {
        if (!$this->triggers->has($triggerKey)) {
            throw ValidationException::forField(
                'trigger_key',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('No provider on this site answers the trigger "%s".', $triggerKey),
            );
        }

        $trigger = $this->triggers->get($triggerKey);

        if (!$trigger instanceof EventTriggerInterface && !$trigger instanceof SweepTriggerInterface) {
            throw ValidationException::forField(
                'trigger_key',
                'This trigger resolves no subject, so a rule on it could never fan out.',
            );
        }
    }

    private function needsApproval(string $actionKey): bool
    {
        return $this->actions->has($actionKey)
            && $this->actions->get($actionKey) instanceof RequiresApprovalBeforeEffect;
    }

    private function requireManage(Principal $principal): void
    {
        $this->guard->requireAny($principal, Capability::MANAGE, Capability::ADMIN);
    }

    private function perPage(int $perPage): int
    {
        return $perPage < 1 ? self::DEFAULT_PER_PAGE : min($perPage, self::MAX_PER_PAGE);
    }

    private static function slug(string $title): string
    {
        $slug = trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $title) ?? ''), '-');

        return $slug === '' ? 'rule-' . substr(hash('sha256', $title), 0, 12) : substr($slug, 0, 100);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    private static function instant(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) && $value !== ''
            ? new \DateTimeImmutable($value, new \DateTimeZone('UTC'))
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeColumn(mixed $value): ?array
    {
        if (\is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return \is_array($decoded) ? $decoded : null;
        }

        return \is_array($value) ? $value : null;
    }

    /**
     * @param array<string, mixed>      $body
     * @param array<string, mixed>|null $existing
     */
    private static function word(array $body, ?array $existing, string $key, string $fallback): string
    {
        $value = $body[$key] ?? ($existing[$key] ?? null);

        return \is_string($value) && $value !== '' ? $value : $fallback;
    }

    /**
     * @param array<string, mixed>      $body
     * @param array<string, mixed>|null $existing
     */
    private static function count(array $body, ?array $existing, string $key, int $fallback): int
    {
        if (\array_key_exists($key, $body)) {
            if (!\is_int($body[$key]) || $body[$key] < 0) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($key, 'Expected a whole number of zero or more.');
            }

            return $body[$key];
        }

        $value = $existing[$key] ?? null;

        return is_numeric($value) ? (int) $value : $fallback;
    }

    /**
     * @param array<string, mixed>      $body
     * @param array<string, mixed>|null $existing
     */
    private static function nullableCount(array $body, ?array $existing, string $key): ?int
    {
        if (\array_key_exists($key, $body)) {
            if ($body[$key] === null) {
                return null;
            }

            if (!\is_int($body[$key]) || $body[$key] < 0) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($key, 'Expected a whole number of zero or more, or null.');
            }

            return $body[$key];
        }

        $value = $existing[$key] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }
}
