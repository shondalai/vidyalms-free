<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationDigest;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationRule;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueuedNotification;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueueState;
use Shondalai\VidyaLMS\Core\Domain\Notification\ScheduledOccurrence;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationQueueRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationRuleRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Context\TriggerContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\EventTriggerInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TriggerOccurrence;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

/**
 * The outbox writer.
 *
 * The enqueue is a direct call from the use case that changes the state,
 * inside a database transaction, and never an event listener. The shipped
 * event bus wraps every listener in a try and logs, so a listener that throws
 * leaves the business state committed and its side effect lost; an outbox
 * written from a listener is therefore not an outbox at all, and the
 * direction that loses mail is state committed with no queue row. A committed
 * enrolment cannot lose its mail because the insert and the enrolment row
 * commit or abort together.
 *
 * Exactly one row is written inside that transaction and it is an intent row:
 * rule, trigger, subject, occurrence and event reference, with no recipient.
 * Audience resolution, policy evaluation and the per-recipient rows belong to
 * the binned fan-out task, because a rule over a five thousand member cohort
 * must not run five thousand policy evaluations inside the transaction that
 * started the cohort.
 */
final class NotificationEnqueue
{
    /**
     * @param array<string, string> $hostFacts what the core cannot know and a
     *                                         trigger may need, such as the
     *                                         base a verification URL is
     *                                         composed against
     */
    public function __construct(
        private readonly NotificationTriggerRegistry $triggers,
        private readonly SqlNotificationRuleRepository $rules,
        private readonly SqlNotificationQueueRepository $queue,
        private readonly ClockPort $clock,
        private readonly DatabasePort $database,
        private readonly UsersPort $users,
        private readonly array $hostFacts = [],
        /**
         * Seeded on first use rather than at boot, on the same pattern the
         * default certificate template already follows: a site that never
         * emits an event never writes a rule row, and a boot never writes.
         */
        private readonly ?EnsureDefaultNotifications $defaults = null,
        /**
         * Optional so an adapter or a test that predates the schedule enum
         * keeps compiling; absent, every kind behaves as immediate, which is
         * exactly the defect this parameter closes and is why the kernel
         * always supplies one.
         */
        private readonly ?NotificationScheduleResolver $schedules = null,
    ) {
    }

    /**
     * Writes one intent row per enabled rule bound to this event.
     *
     * @return list<int> the intent row ids written, which is empty when every
     *                   occurrence was already enqueued
     */
    public function forEvent(DomainEvent $event, ?int $courseId = null): array
    {
        $this->defaults?->execute();

        $written = [];
        $context = TriggerContext::create(
            $this->clock->now(),
            $this->users,
            $this->database,
            'en-GB',
            $this->hostFacts,
        );

        foreach ($this->triggers->forEvent($event) as $trigger) {
            $rules = $this->rules->enabledForTrigger($trigger->key(), $courseId);

            if ($rules === []) {
                continue;
            }

            $parameters = $trigger->parametersFor($event, $context);

            if ($parameters === null) {
                continue;
            }

            [$subjectType, $subjectId] = $trigger->subjectFor($event);
            $occurrenceKey = $this->occurrenceKeyOf($event);

            foreach ($rules as $rule) {
                foreach ($this->occurrencesFor($rule, $parameters, $occurrenceKey, null) as $occurrence) {
                    $id = $this->writeIntent(
                        $rule,
                        $trigger,
                        $event->idempotencyKey(),
                        $occurrence->occurrenceKey,
                        $subjectType,
                        $subjectId,
                        $parameters,
                        $occurrence->runAt,
                    );

                    if ($id !== null) {
                        $written[] = $id;
                    }
                }
            }
        }

        return $written;
    }

    /**
     * The sweep path: an occurrence with no domain event behind it, which
     * must mint a synthetic reference. A cron that runs twice would otherwise
     * send twice.
     */
    public function forOccurrence(NotificationRule $rule, TriggerOccurrence $occurrence): ?int
    {
        if (!$this->triggers->has($rule->triggerKey)) {
            return null;
        }

        $trigger = $this->triggers->get($rule->triggerKey);
        $parameters = $occurrence->parameters + ['recipient_user_id' => $occurrence->recipientUserId];

        // A sweep trigger that named its own instant keeps it; one that did
        // not falls to the rule's schedule, so a relative sweep rule is not
        // silently immediate either. The sweep answers one row, so a
        // recurrence on a sweep rule takes its first occurrence.
        $scheduled = $this->occurrencesFor($rule, $parameters, $occurrence->occurrenceKey, $occurrence->runAt);

        if ($scheduled === []) {
            return null;
        }

        return $this->writeIntent(
            $rule,
            $trigger,
            $occurrence->eventReference,
            $scheduled[0]->occurrenceKey,
            $occurrence->subjectType,
            $occurrence->subjectId,
            $parameters,
            $scheduled[0]->runAt,
        );
    }

    /**
     * The automation engine's entry point: one delivery row for one step.
     *
     * It takes the template key, the category key and the message class
     * explicitly because there is no notification_rules row behind an
     * automation step. Reusing forEvent or forOccurrence would read a rule by
     * the automation rule's integer and increment that unrelated rule's
     * counters, and the queue screen would then report a notification rule
     * sending mail it never sent.
     *
     * It writes a recipient row rather than an intent row for the same
     * reason: the fan-out resolves the audience and the channels from a rule,
     * and an automation step has already decided both. The class still comes
     * from the message and never from a caller's preference, so the send-time
     * policy fold treats an automation message exactly as it treats any
     * other.
     *
     * The automation rule reaches the row only through the producer-qualified
     * dedupe digest: notification_queue.rule_id references notification_rules
     * and stays null here, so an automation rule id may never be read as a
     * notification rule id. A column of its own arrives with automation_runs.
     *
     * @return ?int the queue row id, or null when this step's occurrence was
     *              already enqueued
     */
    public function forAutomationStep(
        int $automationRuleId,
        string $triggerKey,
        string $templateKey,
        string $categoryKey,
        NotificationClass $class,
        string $channelKey,
        TriggerOccurrence $occurrence,
    ): ?int {
        if ($automationRuleId < 1) {
            throw new \InvalidArgumentException('An automation step names its rule.');
        }

        if ($triggerKey === '' || $templateKey === '' || $categoryKey === '' || $channelKey === '') {
            throw new \InvalidArgumentException('An automation step names its trigger, template, category and channel.');
        }

        if ($occurrence->recipientUserId < 1) {
            throw new \InvalidArgumentException('An automation step names one recipient.');
        }

        return $this->queue->enqueue(new QueuedNotification(
            null,
            null,
            $triggerKey,
            $categoryKey,
            $class,
            $templateKey,
            $occurrence->eventReference,
            $occurrence->occurrenceKey,
            NotificationDigest::forDelivery(
                NotificationDigest::automationRuleRef($automationRuleId),
                $triggerKey,
                $occurrence->eventReference,
                $templateKey,
                $channelKey,
                $occurrence->recipientUserId,
                $occurrence->occurrenceKey,
            ),
            $occurrence->runAt ?? $this->clock->now(),
            QueueState::Pending,
            $occurrence->recipientUserId,
            $channelKey,
            $occurrence->subjectType,
            $occurrence->subjectId,
            $occurrence->parameters,
        ));
    }

    /**
     * The instants this rule's schedule produces for this occurrence.
     *
     * With no resolver the answer is the immediate one, which is what every
     * kind did before the resolver existed; with one, an absolute rule waits
     * for its instant, a relative rule steps its anchor's local calendar, and
     * a recurring window mints one occurrence per step. An unresolvable
     * schedule answers an empty list, so the rule enqueues nothing rather
     * than firing now.
     *
     * @param array<string, mixed> $parameters
     *
     * @return list<ScheduledOccurrence>
     */
    private function occurrencesFor(
        NotificationRule $rule,
        array $parameters,
        string $occurrenceKey,
        ?\DateTimeImmutable $runAt,
    ): array {
        if ($this->schedules === null) {
            return [ScheduledOccurrence::once($runAt ?? $this->clock->now(), $occurrenceKey)];
        }

        return $this->schedules->occurrencesFor($rule, $parameters, $occurrenceKey, $runAt);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function writeIntent(
        NotificationRule $rule,
        object $trigger,
        string $eventReference,
        string $occurrenceKey,
        ?string $subjectType,
        ?int $subjectId,
        array $parameters,
        ?\DateTimeImmutable $runAt = null,
    ): ?int {
        if (!$trigger instanceof EventTriggerInterface && !method_exists($trigger, 'messageClass')) {
            return null;
        }

        /** @var \Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerInterface $trigger */
        $ruleId = $rule->id;

        $id = $this->queue->enqueue(new QueuedNotification(
            null,
            $ruleId,
            $rule->triggerKey,
            $rule->categoryKey,
            $trigger->messageClass(),
            $rule->templateKey,
            $eventReference,
            $occurrenceKey,
            NotificationDigest::forIntent(
                NotificationDigest::notificationRuleRef($ruleId),
                $rule->triggerKey,
                $eventReference,
                $occurrenceKey,
            ),
            $runAt ?? $this->clock->now(),
            QueueState::PendingFanout,
            null,
            null,
            $subjectType,
            $subjectId,
            $parameters,
        ));

        if ($id !== null && $ruleId !== null) {
            $this->rules->incrementCounter($ruleId, 'queued_count');
        }

        return $id;
    }

    /**
     * A single-shot event rule's occurrence is the literal "once"; an event
     * that repeats for one subject says so itself.
     */
    private function occurrenceKeyOf(DomainEvent $event): string
    {
        if (method_exists($event, 'occurrenceKey')) {
            $key = $event->occurrenceKey();

            if (\is_string($key) && $key !== '') {
                return $key;
            }
        }

        return \Shondalai\VidyaLMS\Core\Registry\Notification\Notification::OCCURRENCE_ONCE;
    }
}
