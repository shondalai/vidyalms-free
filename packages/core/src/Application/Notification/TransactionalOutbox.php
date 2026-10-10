<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Application\Automation\AutomationOutbox;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * The outbox transaction, in one object the trigger-emitting use cases share.
 *
 * The queue row is written inside the same transaction as the state change
 * that caused it, by the use case itself and never by an event listener. The
 * shipped event bus wraps every listener in a try and logs, by design, so an
 * outbox written from a listener commits the business state and loses the
 * row: the caller gets a 201, the enrolment exists, and the mail it promised
 * does not. That is the direction that loses mail, and the opposite direction
 * (a queue row in an aborted transaction) is already free, because the row
 * rolls back with everything else.
 *
 * The events are dispatched **after** the commit rather than inside it. Two
 * reasons, and both are load-bearing. Dispatch inside the transaction would
 * put every unrelated listener (certificate issuance, the activity
 * projector, the host bridge) inside the business transaction, so a slow
 * webhook would hold a row lock. And a listener that reads the row it was
 * told about must see it committed. The listener's own enqueue then finds the
 * intent row already there and answers null, because the dedupe digest makes
 * the two paths idempotent rather than duplicative.
 */
final class TransactionalOutbox
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly EventBus $events,
        /**
         * Optional so a use case built by a harness with no notification
         * engine still runs; absent, the outbox degrades to the plain
         * transaction plus dispatch, which is what the use cases did before
         * it existed.
         */
        private readonly ?NotificationEnqueue $enqueue = null,
        /**
         * Optional for the same reason, and used only after the commit: the
         * intent row is the one row the transaction writes, so the job that
         * expands it is queued once the row is certain to exist. Absent, the
         * periodic fan-out is what finds the row, which is what happened for
         * the whole of Wave 4a.
         */
        private readonly ?NotificationJobs $jobs = null,
        /**
         * The automation half of the same outbox, behind a holder because the
         * engine is built later in the kernel than this object is.
         *
         * It is here rather than in a listener for the reason the notification
         * intent is: the event bus wraps every listener in a try and logs, so
         * an automation intent written from a listener commits the business
         * state and loses the row. Before it existed an event trigger could
         * never open an automation run at all on a free build, while the
         * builder offered ten of them: a rule on `vidyalms.mail.enrolled`
         * saved, armed and reported enabled, and a real enrolment produced a
         * notification and no automation.
         */
        private readonly ?AutomationOutbox $automations = null,
    ) {
    }

    /**
     * Runs the state write and the intent inserts in one transaction, then
     * dispatches.
     *
     * @template T
     *
     * @param callable(): array{0: T, 1: list<DomainEvent>} $operation the state write, answering its result and the events it produced
     *
     * @return T
     */
    public function commit(callable $operation): mixed
    {
        /** @var list<int> $intents */
        $intents = [];
        /** @var list<int> $runs */
        $runs = [];
        $automations = $this->automations?->runs();

        /** @var array{0: mixed, 1: list<DomainEvent>} $written */
        $written = $this->database->transactional(function () use ($operation, $automations, &$intents, &$runs): array {
            /** @var array{0: mixed, 1: list<DomainEvent>} $result */
            $result = $operation();

            foreach ($result[1] as $event) {
                foreach ($this->enqueue?->forEvent($event, self::courseIdOf($event)) ?? [] as $intentId) {
                    $intents[] = $intentId;
                }

                // The automation intent, in the same transaction and for the
                // same reason: the outbox rule applies to every action and not
                // only to mail.
                foreach ($automations?->forEvent($event, self::courseIdOf($event)) ?? [] as $runId) {
                    $runs[] = $runId;
                }
            }

            return $result;
        });

        // After the commit, for the reason the events are: a job queued
        // inside the transaction can be claimed by a runner in another
        // process before the row it names is visible, and a rolled back
        // transaction would leave a job naming a row that never existed.
        $this->events->afterCommit(function () use ($intents, $runs, $written): void {
            foreach ($intents as $intentId) {
                $this->jobs?->queueFanout($intentId);
            }

            $automationJobs = $runs === [] ? null : $this->automations?->jobs();

            foreach ($runs as $runId) {
                $automationJobs?->queueFanout($runId);
            }

            $this->events->dispatchAll($written[1]);
        });

        /** @var T $value */
        $value = $written[0];

        return $value;
    }

    private static function courseIdOf(DomainEvent $event): ?int
    {
        $courseId = $event->payload()['course_id'] ?? null;

        return \is_numeric($courseId) ? (int) $courseId : null;
    }
}
