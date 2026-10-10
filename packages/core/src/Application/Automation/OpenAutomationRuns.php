<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationOccurrence;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRule;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRun;
use Shondalai\VidyaLMS\Core\Domain\Automation\AutomationRunState;
use Shondalai\VidyaLMS\Core\Domain\Automation\CascadeChain;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRunRepository;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\EventTriggerInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TriggerOccurrence;

/**
 * The outbox write of the automation engine: one intent row per rule per
 * subject, inside the transaction that changed the state.
 *
 * It is a use case and never an event listener, because EventBus wraps every
 * listener in try and catch and logs, so an outbox written from a listener
 * commits the business state and loses the row. Audience resolution, condition
 * re-evaluation and policy evaluation all belong to the binned fan-out task,
 * because a cohort-starting rule over a five thousand member cohort must not
 * run five thousand evaluations inside the transaction that starts the cohort.
 *
 * Several rules on one trigger are opened in run_order with creation time as
 * the deterministic tie-break, which is the order the admin shows. The
 * alternative is a support burden of non-reproducible orderings nobody can
 * debug from a log.
 *
 * **The cascade is stamped here and never by the caller.** An action that
 * opens runs used to be trusted to inherit the emitting run's depth and chain
 * itself, so a third-party action that simply did not call
 * `inheritingCascade()` opened run after run at depth zero with an empty
 * chain: two mutually emitting rules at cooldown zero opened one run per turn
 * against a ceiling of five and never approached it. The engine now holds the
 * frame that is executing, so an action opening runs by any route, including
 * one that never heard of a cascade, is stamped with the depth and chain of
 * the step that is calling it.
 */
final class OpenAutomationRuns
{
    public function __construct(
        private readonly SqlAutomationRuleRepository $rules,
        private readonly SqlAutomationRunRepository $runs,
        private readonly AutomationLoopGuard $loops,
        /**
         * The frame stack the engine pushes before it calls an action. Absent,
         * an explicit chain is still honoured and a caller that passes nothing
         * opens at depth zero, which is what a sweep genuinely is.
         */
        private readonly ?AutomationCascade $cascade = null,
        /**
         * Present so an event that has just committed can open the runs its
         * triggers name. Absent, only the sweep and the emitting paths open
         * runs, which is the whole of what the engine could do before.
         */
        private readonly ?NotificationTriggerRegistry $triggers = null,
        /** Woken after the intent row commits, so a run does not wait a bin. */
        private readonly ?AutomationJobs $jobs = null,
    ) {
    }

    /**
     * Opens a run for every enabled rule on this trigger, and answers the
     * ones it opened.
     *
     * The inherited chain is derived from the step frame in force rather than
     * read off the occurrence, because TriggerOccurrence carries the emitting
     * rule id and the depth and deliberately not the whole chain: a trigger
     * provider that could set its own chain could shorten it, which is the
     * failure the inheritance exists to prevent. An explicit chain is still
     * accepted, for a caller that holds the emitting run and no frame.
     *
     * @return list<int> the run ids
     */
    public function forOccurrence(
        string $triggerKey,
        TriggerOccurrence $occurrence,
        bool $dryRun = false,
        ?CascadeChain $inherited = null,
    ): array {
        $opened = [];

        foreach ($this->rules->enabledForTrigger($triggerKey) as $rule) {
            $runId = $this->forRule($rule, $triggerKey, $occurrence, $dryRun, $inherited);

            if ($runId !== null) {
                $opened[] = $runId;
            }
        }

        return $opened;
    }

    /**
     * The event path: one committed domain event, every automation rule whose
     * trigger answers it.
     *
     * Without this an event trigger could never open a run at all on a free
     * build, while the builder offered ten of them: a rule on
     * `vidyalms.mail.enrolled` saved, armed and reported enabled, and a real
     * enrolment produced a notification and no automation. The design's own
     * rule is that the outbox rule applies to every action and not only to
     * mail, so this is called from inside the same commit the state change
     * runs in, exactly as the notification intent is.
     *
     * @return list<int> the run ids
     */
    public function forEvent(DomainEvent $event, ?int $courseId = null): array
    {
        if ($this->triggers === null) {
            return [];
        }

        $opened = [];

        foreach ($this->triggers->forEvent($event) as $trigger) {
            if (!$trigger instanceof EventTriggerInterface) {
                continue;
            }

            $rules = $this->rules->enabledForTrigger($trigger->key());

            if ($rules === []) {
                continue;
            }

            [$subjectType, $subjectId] = $trigger->subjectFor($event);
            $occurrence = TriggerOccurrence::forSweep(
                $trigger->key(),
                self::recipientOf($event, $subjectType, $subjectId),
                self::occurrenceKeyOf($event),
                $subjectType,
                $subjectId,
            );

            foreach ($rules as $rule) {
                // The course scope is the one scope the engine can honour
                // without the tenancy read, because the rule carries the id
                // and the event carries it too.
                if ($rule->courseId !== null && $courseId !== null && $rule->courseId !== $courseId) {
                    continue;
                }

                $runId = $this->forRule($rule, $trigger->key(), $occurrence);

                if ($runId !== null) {
                    $opened[] = $runId;
                }
            }
        }

        return $opened;
    }

    /**
     * One rule, one occurrence.
     *
     * Answers null for a replay, for a rule the loop guard refuses and for a
     * rule that has never been armed. The last is not an oversight: the run
     * executes as the rule's authoriser, and a run with no named person is
     * exactly the audit gap the arming columns exist to close.
     */
    public function forRule(
        AutomationRule $rule,
        string $triggerKey,
        TriggerOccurrence $occurrence,
        bool $dryRun = false,
        ?CascadeChain $inherited = null,
    ): ?int {
        if ($rule->id === null) {
            return null;
        }

        if (!$dryRun && $rule->authorisingUserId() === null) {
            return null;
        }

        $frame = $this->cascade?->current();
        $chain = $inherited ?? ($frame === null ? CascadeChain::empty() : $frame->cascadeChain->with($frame->ruleId));
        $depth = $frame === null ? $occurrence->cascadeDepth : $frame->cascadeDepth + 1;
        $emittingRuleId = $frame === null ? $occurrence->emittingRuleId : $frame->ruleId;

        if ($emittingRuleId !== null) {
            $chain = $chain->with($emittingRuleId);
        }

        $refusal = $this->loops->refusalFor(
            $rule,
            $occurrence->subjectType,
            $occurrence->subjectId,
            $depth,
            $chain,
            $dryRun,
        );

        if ($refusal !== null) {
            return null;
        }

        $runId = $this->runs->open(new AutomationRun(
            null,
            $rule->id,
            $rule->version,
            $triggerKey,
            $occurrence->eventReference,
            $this->occurrenceKeyFor($rule, $occurrence, $dryRun),
            $occurrence->subjectType,
            $occurrence->subjectId,
            $rule->authorisingUserId(),
            $dryRun,
            AutomationRunState::PendingFanout,
            $depth,
            $chain->with($rule->id),
            recipientUserId: $occurrence->recipientUserId > 0 ? $occurrence->recipientUserId : null,
        ));

        if ($runId !== null) {
            $this->jobs?->queueFanout($runId);
        }

        return $runId;
    }

    /**
     * The occurrence key this entry is written under.
     *
     * `always` is the only mode that wants a distinct run inside one
     * occurrence, and it gets one by carrying the entry ordinal in the key
     * rather than by dropping the unique index that is the re-entry guard.
     * `never` and `on_new_occurrence` pass the occurrence key through, and
     * the difference between them is the refusal the loop guard makes.
     */
    private function occurrenceKeyFor(AutomationRule $rule, TriggerOccurrence $occurrence, bool $dryRun): string
    {
        if ($dryRun || !$rule->reentry->allowsRepeatWithinOneOccurrence()) {
            return $occurrence->occurrenceKey;
        }

        $ordinal = $this->runs->countForSubject(
            $rule->id ?? 0,
            $occurrence->subjectType,
            $occurrence->subjectId,
        ) + 1;

        return AutomationOccurrence::repeatKey($occurrence->occurrenceKey, $ordinal);
    }

    /**
     * The person this occurrence is about, which is the subject only when the
     * subject is a person.
     */
    private static function recipientOf(DomainEvent $event, ?string $subjectType, ?int $subjectId): int
    {
        if ($subjectType === 'user' && $subjectId !== null && $subjectId > 0) {
            return $subjectId;
        }

        $userId = $event->payload()['user_id'] ?? null;

        return \is_numeric($userId) ? (int) $userId : 0;
    }

    private static function occurrenceKeyOf(DomainEvent $event): string
    {
        $key = $event->idempotencyKey();

        // The occurrence key column is bounded and an event's own key is not,
        // so a long one is hashed rather than truncated: truncation merges two
        // distinct occurrences onto one row and the merge is silent.
        return \strlen($key) <= 64 ? $key : substr(hash('sha256', $key), 0, 64);
    }
}
