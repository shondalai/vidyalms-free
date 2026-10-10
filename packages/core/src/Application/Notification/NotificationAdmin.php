<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityRuleValidator;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTreeCodec;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationDigest;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationRule;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueuedNotification;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\SqlAvailabilityRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationPreferenceRepository as Prefs;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationQueueRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationSuppressionRepository;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationChannelRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;

/**
 * The operator surface: rules, the queue, templates, the policy matrix,
 * suppressions and the consent ledger.
 *
 * Two refusals here are legal controls rather than tidiness. A forced policy
 * state on an optional or marketing category is refused at save, because an
 * objection to direct marketing admits no balancing. And a rule may not name
 * a template whose trigger declares a lower class than the rule's own
 * category implies: the class is read from the trigger that owns the
 * template, never stored on the rule, so a rule cannot raise it.
 */
final class NotificationAdmin
{
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly SqlNotificationRuleRepository $rules,
        private readonly SqlNotificationQueueRepository $queue,
        private readonly Prefs $preferences,
        private readonly SqlNotificationSuppressionRepository $suppressions,
        private readonly \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationConsentRepository $consents,
        private readonly NotificationTriggerRegistry $triggers,
        private readonly NotificationChannelRegistry $channels,
        private readonly AccessGuard $guard,
        /**
         * The same save-time pass and the same reference table the
         * availability rule save uses. Optional so a harness that predates
         * the restriction engine still builds this class; absent, a
         * conditions tree is stored unvalidated and unreferenced, which is
         * exactly the state this pair closes.
         */
        private readonly ?AvailabilityRuleValidator $availabilityValidator = null,
        private readonly ?SqlAvailabilityRuleRepository $availabilityRules = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listRules(Principal $principal, ?string $triggerKey, ?string $state, int $page, int $perPage): array
    {
        $this->requireManage($principal);
        $perPage = $this->perPage($perPage);
        $result = $this->rules->page($triggerKey, $state, $perPage, max(0, ($page - 1) * $perPage));

        return [
            'items' => array_map($this->presentRule(...), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getRule(Principal $principal, int $id): array
    {
        $this->requireManage($principal);

        $rule = $this->rules->find($id);

        if ($rule === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('NotificationRule', $id);
        }

        return $this->presentRule($rule);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function saveRule(Principal $principal, ?int $id, array $body): array
    {
        $this->requireManage($principal);

        $existing = $id === null ? null : $this->rules->find($id);

        if ($id !== null && $existing === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('NotificationRule', $id);
        }

        $ruleKey = $this->stringOr($body, 'rule_key', $existing?->ruleKey);
        $triggerKey = $this->stringOr($body, 'trigger_key', $existing?->triggerKey);
        $errors = [];

        if ($ruleKey === null || $ruleKey === '') {
            $errors['rule_key'] = 'A rule key is required.';
        }

        if ($triggerKey === null || !$this->triggers->has($triggerKey)) {
            $errors['trigger_key'] = 'Unknown trigger.';
        }

        $channels = $body['channels'] ?? ($existing->channels ?? []);

        if (!\is_array($channels) || $channels === []) {
            $errors['channels'] = 'At least one channel is required.';
            $channels = [];
        }

        foreach ($channels as $channelKey) {
            if (!\is_string($channelKey) || !$this->channels->has($channelKey)) {
                $errors['channels'] = 'Unknown channel.';
            }
        }

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ValidationException($errors);
        }

        /** @var string $triggerKey */
        $trigger = $this->triggers->get($triggerKey);
        $templateKey = $this->stringOr($body, 'template_key', $existing?->templateKey) ?? $trigger->defaultTemplateKey();
        $categoryKey = $this->stringOr($body, 'category_key', $existing?->categoryKey) ?? $trigger->categoryKey();

        // The class is the trigger's, and a rule may not name a category
        // whose class outranks it: that would be a one-field bypass of
        // consent and of the unsubscribe headers.
        $this->refuseClassEscalation($trigger->messageClass(), $categoryKey);

        $conditions = $body['conditions'] ?? null;
        $channelKeys = [];

        foreach ($channels as $channelKey) {
            $channelKeys[] = (string) $channelKey;
        }

        $tree = \is_array($conditions) ? AvailabilityTreeCodec::decode($conditions) : $existing?->conditions;
        $courseId = $this->intOrNull($body['course_id'] ?? null) ?? $existing?->courseId;

        // The identical save-time pass the availability rule save runs, on
        // the notification_rule surface. Without it a conditions tree stored
        // here got no applicability refusal (a content-gating-only leaf such
        // as a time window or an address range stored happily on a rule that
        // has no item in hand), no unregistered-condition refusal, no payload
        // version refusal, no cycle check, and above all no reference rows:
        // a condition naming lesson 88 survived a course copy still pointing
        // at the old course's lesson, which is the failure the whole
        // update-after-restore contract exists to prevent.
        $report = $tree === null || $tree->root === null
            ? null
            : $this->availabilityValidator?->validate(
                $tree,
                AvailabilitySurface::NotificationRule,
                null,
                $courseId,
            );

        $saved = $this->rules->save(new NotificationRule(
            $existing?->id,
            (string) $ruleKey,
            $triggerKey,
            $this->stringOr($body, 'audience_key', $existing?->audienceKey) ?? $trigger->defaultAudienceKey(),
            $categoryKey,
            $templateKey,
            $channelKeys,
            $this->stringOr($body, 'schedule_kind', $existing?->scheduleKind) ?? NotificationRule::KIND_IMMEDIATE,
            $this->stringOr($body, 'anchor_key', $existing?->anchorKey),
            \is_array($body['schedule'] ?? null) ? $body['schedule'] : ($existing->schedule ?? []),
            \is_array($body['definition'] ?? null) ? $body['definition'] : ($existing->definition ?? []),
            \is_array($body['audience_config'] ?? null) ? $body['audience_config'] : ($existing->audienceConfig ?? []),
            $tree,
            $this->stringList($body['suppresses'] ?? null) ?? ($existing->suppresses ?? []),
            $courseId,
            $this->intOrNull($body['cohort_id'] ?? null) ?? $existing?->cohortId,
            $this->stringOr($body, 'state', $existing?->state) ?? NotificationRule::STATE_DISABLED,
            $existing->queuedCount ?? 0,
            $existing->sentCount ?? 0,
            $existing->suppressedCount ?? 0,
            $existing->createdBy ?? $principal->userId,
        ));

        // The reference rows, stamped with this owner type, so "every rule
        // that references lesson 88" is one indexed query across all three
        // owners and a course copy remaps a notification condition exactly as
        // it remaps a content gate.
        if ($saved->id !== null) {
            $this->availabilityRules?->replaceReferences(
                SqlAvailabilityRuleRepository::OWNER_NOTIFICATION_RULE,
                $saved->id,
                AvailabilitySurface::NotificationRule,
                $report === null ? [] : $report->references,
            );
        }

        return $this->presentRule($saved);
    }

    public function deleteRule(Principal $principal, int $id): void
    {
        $this->requireManage($principal);

        if ($this->rules->find($id) === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('NotificationRule', $id);
        }

        $this->rules->delete($id);
    }

    /**
     * @return array<string, mixed>
     */
    public function listQueue(Principal $principal, ?string $state, ?int $ruleId, int $page, int $perPage): array
    {
        $this->requireManage($principal);
        $perPage = $this->perPage($perPage);
        $result = $this->queue->page($state, $ruleId, $perPage, max(0, ($page - 1) * $perPage));

        return [
            'items' => array_map($this->presentQueueRow(...), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            'counts_by_state' => $result['counts'],
        ];
    }

    /**
     * The only way an operator reaches the cancelled state.
     *
     * A row already claimed for sending is refused rather than pretended, and
     * a cancelled row keeps its dedupe digest so the occurrence is never
     * re-enqueued behind the operator's back.
     *
     * @return array<string, mixed>
     */
    public function cancelQueued(Principal $principal, int $id): array
    {
        $this->requireManage($principal);

        $row = $this->queue->find($id);

        if ($row === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('NotificationQueue', $id);
        }

        if (!$this->queue->cancel($id)) {
            throw new ConflictException('This message is no longer pending.');
        }

        $cancelled = $this->queue->find($id);

        return $this->presentQueueRow($cancelled ?? $row);
    }



    /**
     * @return array<string, mixed>
     */
    public function policyMatrix(Principal $principal): array
    {
        $this->requireManage($principal);

        $stored = $this->preferences->policies();
        $cells = [];

        foreach ($this->triggers->categories() as $categoryKey => $category) {
            $class = NotificationClass::from($category['class']);

            foreach ($this->channels->keys() as $channelKey) {
                $cell = $stored[$categoryKey . '|' . $channelKey] ?? null;

                $cells[] = [
                    'category_key' => $categoryKey,
                    'channel_key' => $channelKey,
                    'state' => $cell['state'] ?? Prefs::POLICY_PERMITTED,
                    'default_frequency' => $cell['default_frequency'] ?? Prefs::FREQUENCY_IMMEDIATE,
                    'allowed_states' => $class->allowsForcedPolicy()
                        ? Prefs::policyStates()
                        : [Prefs::POLICY_DISALLOWED, Prefs::POLICY_PERMITTED],
                ];
            }
        }

        return ['cells' => $cells];
    }

    /**
     * @param list<mixed> $cells a decoded request body, so each entry is checked rather than trusted
     *
     * @return array<string, mixed>
     */
    public function savePolicyMatrix(Principal $principal, array $cells): array
    {
        $this->requireManage($principal);

        $categories = $this->triggers->categories();
        $errors = [];
        $accepted = [];

        foreach ($cells as $index => $cell) {
            $categoryKey = \is_array($cell) ? ($cell['category_key'] ?? null) : null;
            $channelKey = \is_array($cell) ? ($cell['channel_key'] ?? null) : null;
            $state = \is_array($cell) ? ($cell['state'] ?? null) : null;
            $frequency = \is_array($cell) ? ($cell['default_frequency'] ?? Prefs::FREQUENCY_IMMEDIATE) : null;

            if (!\is_string($categoryKey) || !isset($categories[$categoryKey])) {
                $errors['cells.' . $index . '.category_key'] = 'Unknown category.';
                continue;
            }

            if (!\is_string($channelKey) || !$this->channels->has($channelKey)) {
                $errors['cells.' . $index . '.channel_key'] = 'Unknown channel.';
                continue;
            }

            if (!\is_string($state) || !\in_array($state, Prefs::policyStates(), true)) {
                $errors['cells.' . $index . '.state'] = 'Unknown state.';
                continue;
            }

            $class = NotificationClass::from($categories[$categoryKey]['class']);

            if ($state === Prefs::POLICY_FORCED && !$class->allowsForcedPolicy()) {
                $errors['cells.' . $index . '.state'] =
                    'An optional or marketing category may not be forced: an objection admits no balancing.';
                continue;
            }

            $accepted[] = [
                $categoryKey,
                $channelKey,
                $state,
                \is_string($frequency) && \in_array($frequency, Prefs::frequencies(), true)
                    ? $frequency
                    : Prefs::FREQUENCY_IMMEDIATE,
            ];
        }

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ValidationException($errors);
        }

        foreach ($accepted as [$categoryKey, $channelKey, $state, $frequency]) {
            $this->preferences->setPolicy($categoryKey, $channelKey, $state, $frequency);
        }

        return $this->policyMatrix($principal);
    }

    /**
     * @return array<string, mixed>
     */
    public function listSuppressions(Principal $principal, ?string $channelKey, ?string $address, int $page, int $perPage): array
    {
        $this->requireManage($principal);
        $perPage = $this->perPage($perPage);

        // The lookup takes an address to hash rather than letting an operator
        // browse digests, and the address is never echoed back.
        $digest = $address === null || $address === '' ? null : NotificationDigest::forAddress($address);
        $result = $this->suppressions->page($channelKey, $digest, $perPage, max(0, ($page - 1) * $perPage));

        return [
            'items' => array_map($this->presentSuppression(...), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public function suppressAddress(Principal $principal, array $body): array
    {
        $this->requireManage($principal);

        $channelKey = $this->stringOr($body, 'channel_key', null);
        $address = $this->stringOr($body, 'address', null);
        $action = $this->stringOr($body, 'action', null);
        $errors = [];

        if ($channelKey === null || !$this->channels->has($channelKey)) {
            $errors['channel_key'] = 'Unknown channel.';
        }

        if ($address === null || $address === '') {
            $errors['address'] = 'An address is required.';
        }

        if ($action !== 'suppress' && $action !== 'release') {
            $errors['action'] = 'Unknown action.';
        }

        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ValidationException($errors);
        }

        /** @var string $channelKey */
        /** @var string $address */
        $digest = NotificationDigest::forAddress($address);

        if ($action === 'release') {
            $this->suppressions->release($channelKey, $digest);
        } else {
            $this->suppressions->suppress(
                $channelKey,
                $digest,
                SqlNotificationSuppressionRepository::REASON_MANUAL,
                $this->stringOr($body, 'diagnostic', null),
            );
        }

        return $this->listSuppressions($principal, $channelKey, $address, 1, self::DEFAULT_PER_PAGE);
    }

    /**
     * @return array<string, mixed>
     */
    public function listConsents(Principal $principal, ?int $userId, ?string $categoryKey, int $page, int $perPage): array
    {
        $this->requireManage($principal);
        $perPage = $this->perPage($perPage);
        $result = $this->consents->page($userId, $categoryKey, $perPage, max(0, ($page - 1) * $perPage));

        return [
            'items' => array_map($this->presentConsent(...), $result['items']),
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    private function requireManage(Principal $principal): void
    {
        $this->guard->requireAny($principal, Capability::MANAGE, Capability::ADMIN);
    }

    private function refuseClassEscalation(NotificationClass $triggerClass, string $categoryKey): void
    {
        $categories = $this->triggers->categories();
        $declared = $categories[$categoryKey]['class'] ?? null;

        if ($declared === null) {
            throw ValidationException::forField('category_key', 'Unknown category.');
        }

        if (NotificationClass::from($declared)->rank() > $triggerClass->rank()) {
            throw ValidationException::forField(
                'category_key',
                'A rule may not raise the message class of the template it names.',
            );
        }
    }


    /**
     * @return array<string, mixed>
     */
    private function presentRule(NotificationRule $rule): array
    {
        $available = $this->triggers->has($rule->triggerKey);
        $missing = $available ? [] : [$rule->triggerKey];

        foreach ($rule->channels as $channelKey) {
            if (!$this->channels->has($channelKey)) {
                $missing[] = $channelKey;
            }
        }

        return [
            'id' => $rule->id,
            'rule_key' => $rule->ruleKey,
            'trigger_key' => $rule->triggerKey,
            'definition' => $rule->definition === [] ? null : $rule->definition,
            'conditions' => $rule->conditions === null ? null : AvailabilityTreeCodec::toArray($rule->conditions),
            'audience_key' => $rule->audienceKey,
            'audience_config' => $rule->audienceConfig === [] ? null : $rule->audienceConfig,
            'category_key' => $rule->categoryKey,
            'class' => $available
                ? $this->triggers->get($rule->triggerKey)->messageClass()->value
                : NotificationClass::Transactional->value,
            'template_key' => $rule->templateKey,
            'schedule' => [
                'kind' => $rule->scheduleKind,
                'anchor_key' => $rule->anchorKey,
                'params' => $rule->schedule,
            ],
            'suppresses' => $rule->suppresses,
            'channels' => $rule->channels,
            'course_id' => $rule->courseId,
            'cohort_id' => $rule->cohortId,
            'state' => $rule->state,
            'counters' => [
                'queued' => $rule->queuedCount,
                'sent' => $rule->sentCount,
                'suppressed' => $rule->suppressedCount,
                'suppressed_by_reason' => $rule->id === null ? [] : $this->queue->suppressionCountsFor($rule->id),
            ],
            'inert' => $missing !== [],
            'missing_providers' => array_values(array_unique($missing)),
            'created_by' => $rule->createdBy,
            'updated_at' => $rule->updatedAt?->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentQueueRow(QueuedNotification $row): array
    {
        return [
            'id' => $row->id,
            'rule_id' => $row->ruleId,
            'trigger_key' => $row->triggerKey,
            'category_key' => $row->categoryKey,
            'class' => $row->class->value,
            'subject_type' => $row->subjectType,
            'subject_id' => $row->subjectId,
            'recipient_user_id' => $row->recipientUserId,
            'channel_key' => $row->channelKey,
            'template_key' => $row->templateKey,
            'state' => $row->state->value,
            'occurrence_key' => $row->occurrenceKey,
            'run_at' => $row->runAt->format(\DateTimeInterface::ATOM),
            'next_attempt_at' => $row->nextAttemptAt?->format(\DateTimeInterface::ATOM),
            'attempts' => $row->attempts,
            'suppressed_reason' => $row->suppressedReason,
            'last_error' => $row->lastError,
            'recipient_timezone' => $row->recipientTimezone,
            'recipient_locale' => $row->recipientLocale,
            'sent_at' => $row->sentAt?->format(\DateTimeInterface::ATOM),
            'created_at' => $row->createdAt?->format(\DateTimeInterface::ATOM),
        ];
    }


    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function presentSuppression(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'channel_key' => (string) $row['channel_key'],
            'address_digest' => (string) $row['address_digest'],
            'reason' => (string) $row['reason'],
            'soft_bounce_count' => (int) ($row['soft_bounce_count'] ?? 0),
            'first_seen_at' => (string) $row['first_seen_at'],
            'last_seen_at' => (string) $row['last_seen_at'],
            'released_at' => $row['released_at'] === null ? null : (string) $row['released_at'],
            'active' => $row['released_at'] === null,
            'diagnostic' => $row['diagnostic'] === null ? null : (string) $row['diagnostic'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function presentConsent(array $row): array
    {
        $evidence = null;
        $raw = $row['evidence'] ?? null;

        if (\is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $evidence = \is_array($decoded) ? $decoded : null;
        }

        return [
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'category_key' => (string) $row['category_key'],
            'state' => (string) $row['state'],
            'basis' => (string) $row['basis'],
            'source' => (string) $row['source'],
            'evidence' => $evidence,
            'actor_user_id' => $row['actor_user_id'] === null ? null : (int) $row['actor_user_id'],
            'occurred_at' => (string) $row['occurred_at'],
        ];
    }

    private function perPage(int $perPage): int
    {
        return min(self::MAX_PER_PAGE, max(1, $perPage));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function stringOr(array $body, string $key, ?string $default): ?string
    {
        $value = $body[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    /**
     * @return list<string>|null
     */
    private function stringList(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $list = [];

        foreach ($value as $item) {
            if (\is_string($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    private function intOrNull(mixed $value): ?int
    {
        return \is_numeric($value) ? (int) $value : null;
    }
}
