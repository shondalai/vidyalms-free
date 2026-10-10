<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\EnqueuePeriodicTasks;
use Shondalai\VidyaLMS\Core\Domain\Events\LearnerEnrolled;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationRule;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueuedNotification;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueueState;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Mail\MailMessage;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\EnrolmentAnchor;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\NotificationFanoutTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\NotificationSendTask;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\EventTriggerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\Context\TriggerContext;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\AcmeThingHappened;

/**
 * The notification engine's own defects, one test each.
 *
 * Every one of these passed a green suite before it was fixed, because the
 * machinery was correct and something was not wired to it: a subscription
 * list frozen before the package that needed it registered, a schedule
 * column nothing read, a recovery claim whose predicate the statement itself
 * satisfied, and two scheduled tasks nothing ever started.
 */
final class NotificationEngineDefectsTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-04-01 10:00:00');
    }

    /**
     * A trigger bound to an event class no shipped trigger names, registered
     * **after** the kernel was built, which is when every Pro and
     * third-party package registers.
     *
     * The subscriber used to loop the registry once inside the kernel
     * constructor and subscribe to what it found, so the list was frozen
     * before the hook that adds providers had run: the first trigger that
     * needed a new event class needed a core edit.
     */
    public function testATriggerRegisteredAfterBootReachesTheQueue(): void
    {
        $trigger = new class () implements EventTriggerInterface {
            public function key(): string
            {
                return 'acme.mail.thing_happened';
            }

            public function label(): string
            {
                return 'acme.mail.thing_happened';
            }

            public function kind(): string
            {
                return EventTriggerInterface::KIND_EVENT;
            }

            public function categoryKey(): string
            {
                return 'announcements';
            }

            public function messageClass(): NotificationClass
            {
                return NotificationClass::Operational;
            }

            public function defaultTemplateKey(): string
            {
                return 'acme.mail.thing_happened';
            }

            public function defaultAudienceKey(): string
            {
                return 'learner';
            }

            public function declaredPlaceholders(): array
            {
                return [];
            }

            public function eventClasses(): array
            {
                return [AcmeThingHappened::class];
            }

            public function parametersFor(DomainEvent $event, TriggerContext $context): ?array
            {
                return $event instanceof AcmeThingHappened
                    ? ['user_id' => $event->userId, 'course_id' => $event->courseId]
                    : null;
            }

            public function subjectFor(DomainEvent $event): array
            {
                return ['course', $event instanceof AcmeThingHappened ? $event->courseId : null];
            }
        };

        $this->fixture->kernel->registries->notificationTriggers->register($trigger);

        $this->fixture->kernel->notificationRules->save(new NotificationRule(
            null,
            $trigger->key(),
            $trigger->key(),
            'learner',
            $trigger->categoryKey(),
            $trigger->defaultTemplateKey(),
            ['email'],
            NotificationRule::KIND_IMMEDIATE,
            null,
            [],
            [],
            [],
            null,
            [],
            null,
            null,
            NotificationRule::STATE_ENABLED,
        ));

        $this->fixture->kernel->events->dispatch(new AcmeThingHappened(
            $this->fixture->env->clock->now(),
            ManagementFixture::LEARNER_ID,
            $this->fixture->courseId,
        ));

        self::assertSame(
            1,
            $this->fixture->env->database->count(
                Query::from('notification_queue')->where('trigger_key', '=', $trigger->key()),
            ),
            'a trigger registered after the kernel was built never reached the queue',
        );
    }

    /**
     * An absolute schedule waits for its instant. Every kind behaved as
     * immediate, so a rule authored to fire on 1 June fired the moment the
     * event did and the schedule column the admin screen offers had nothing
     * behind it.
     */
    public function testAnAbsoluteScheduleRunsAtItsStoredInstant(): void
    {
        $this->rule(NotificationRule::KIND_ABSOLUTE, null, ['at' => '2026-06-01T09:00:00+00:00']);
        $this->enrol();

        self::assertSame('2026-06-01 09:00:00', $this->intentRunAt());
    }

    /**
     * A relative schedule steps the anchor's own local calendar. The seven
     * day nudge fired on day zero.
     */
    public function testARelativeScheduleStepsTheAnchor(): void
    {
        $this->rule(
            NotificationRule::KIND_RELATIVE,
            EnrolmentAnchor::KEY,
            ['offset_seconds' => 7 * 86400, 'offset_unit' => 'days', 'direction' => 'after'],
        );
        $this->enrol();

        $runAt = $this->intentRunAt();
        self::assertNotNull($runAt);
        self::assertNotSame('2026-04-01 10:00:00', $runAt, 'a relative schedule fired immediately');
    }

    /**
     * A recurring window bounded at eleven occurrences enqueues eleven rows
     * with eleven distinct occurrence keys. It enqueued one and silently
     * dropped ten, because occurrence_key is what makes the other six digest
     * components distinct.
     */
    public function testARecurringWindowMintsOneOccurrencePerStep(): void
    {
        $this->rule(
            NotificationRule::KIND_RECURRING_WINDOW,
            EnrolmentAnchor::KEY,
            ['every' => 7, 'every_unit' => 'days', 'until' => 70, 'until_unit' => 'days'],
        );
        $this->enrol();

        $rows = $this->fixture->env->database->selectAll(
            Query::from('notification_queue')
                ->select(['occurrence_key'])
                ->where('state', '=', QueueState::PendingFanout->value),
        );

        $keys = array_unique(array_map(static fn (array $row): string => (string) $row['occurrence_key'], $rows));

        self::assertCount(11, $keys, 'a bounded recurrence produced one occurrence instead of eleven');
    }

    /**
     * A weekly recurrence spanning a daylight-saving transition lands on the
     * same local wall-clock time every week, which is what stepping the
     * local calendar means and what adding multiples of 604800 does not do.
     */
    public function testAWeeklyRecurrenceKeepsItsLocalWallClockAcrossADstTransition(): void
    {
        $this->fixture->env->database->update(
            'courses',
            ['timezone' => 'Europe/London'],
            ['id' => $this->fixture->courseId],
        );

        // 09:00 GMT on 18 March, so occurrence three crosses the spring
        // transition on 29 March.
        $this->fixture->env->clock->set('2026-03-18 09:00:00');
        $this->fixture->env->database->update(
            'enrolments',
            ['enrolled_at' => '2026-03-18 09:00:00'],
            ['course_id' => $this->fixture->courseId, 'user_id' => ManagementFixture::LEARNER_ID],
        );

        $this->rule(
            NotificationRule::KIND_RECURRING_WINDOW,
            EnrolmentAnchor::KEY,
            ['every' => 7, 'every_unit' => 'days', 'until' => 21, 'until_unit' => 'days'],
        );
        $this->enrol();

        $rows = $this->fixture->env->database->selectAll(
            Query::from('notification_queue')
                ->select(['run_at'])
                ->where('state', '=', QueueState::PendingFanout->value)
                ->orderBy('run_at'),
        );

        $local = array_map(
            static fn (array $row): string => (new \DateTimeImmutable((string) $row['run_at'], new \DateTimeZone('UTC')))
                ->setTimezone(new \DateTimeZone('Europe/London'))
                ->format('H:i'),
            $rows,
        );

        self::assertNotSame([], $local);
        self::assertSame(['09:00'], array_values(array_unique($local)), (string) json_encode($rows));
    }

    /**
     * The recovery claim. Three runners each saw one lease-expired row, each
     * claimed it because the predicate was satisfied by the value the
     * statement itself wrote, and each proceeded to send.
     */
    public function testALeaseExpiredRowIsClaimedByExactlyOneRunner(): void
    {
        $this->enrol();
        $this->fixture->kernel->notificationFanout->run();

        $row = $this->fixture->env->database->selectOne(
            Query::from('notification_queue')->where('state', '=', QueueState::Pending->value),
        );

        self::assertNotNull($row);
        $id = (int) $row['id'];

        self::assertTrue($this->fixture->kernel->notificationQueue->claim($id, 'dead-runner'));

        // The lease passes.
        $this->fixture->env->clock->set('2026-04-01 11:00:00');

        // Every runner reclaims, and exactly one wins the pending row after.
        $this->fixture->kernel->notificationQueue->reclaimExpiredLeases(50);

        $claims = array_filter([
            $this->fixture->kernel->notificationQueue->claim($id, 'runner-a'),
            $this->fixture->kernel->notificationQueue->claim($id, 'runner-b'),
            $this->fixture->kernel->notificationQueue->claim($id, 'runner-c'),
        ]);

        self::assertCount(1, $claims, 'more than one runner owned a lease-expired row');
    }

    /**
     * A claim from "sending" is refused outright, because its predicate is
     * satisfied by the very value the statement writes.
     */
    public function testAClaimFromSendingIsRefusedAsUnconditional(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->fixture->kernel->notificationQueue->claim(1, 'runner', QueueState::Sending->value);
    }

    /**
     * Both tasks are started by the clock and nothing else ever queues them,
     * so a build where they did not declare it left every intent row in
     * pending_fanout and every queue row pending, for the life of the site,
     * on both hosts.
     */
    public function testTheFanoutAndSendTasksAreStartedByTheClock(): void
    {
        $tasks = $this->fixture->kernel->registries->scheduledTasks;

        self::assertInstanceOf(RunsPeriodically::class, $tasks->get(NotificationFanoutTask::TYPE));
        self::assertInstanceOf(RunsPeriodically::class, $tasks->get(NotificationSendTask::TYPE));

        // The turn of the clock both adapters actually call. Neither holds a
        // task type, a bin count or a bucket, so what the loop enqueues is
        // exactly what the providers declare.
        $queued = (new EnqueuePeriodicTasks(
            $tasks,
            $this->fixture->env->scheduler,
            $this->fixture->env->clock,
        ))->execute();

        self::assertContains(NotificationFanoutTask::TYPE, $queued['tasks']);
        self::assertContains(NotificationSendTask::TYPE, $queued['tasks']);
    }

    /**
     * The intent row is terminal but is not a delivery, so it may not borrow
     * "sent": every occurrence inflated the delivered count on the queue
     * screen by one.
     */
    public function testAnIntentRowTerminatesAsFannedOutRatherThanSent(): void
    {
        $this->enrol();
        $this->fixture->kernel->notificationFanout->run();

        $intent = $this->fixture->env->database->selectOne(
            Query::from('notification_queue')->whereNull('recipient_user_id')->orderBy('id'),
        );

        self::assertNotNull($intent);
        self::assertSame(QueueState::FannedOut->value, (string) $intent['state']);
    }

    /**
     * A channel that throws must not abandon the batch, and the queue owns
     * retry: the throw is classified as a transient failure, the attempt
     * burns and the row goes back to pending with a backoff.
     */
    public function testAThrowingChannelBurnsAnAttemptRatherThanAbandoningTheBatch(): void
    {
        $this->enrol();
        $this->fixture->kernel->notificationFanout->run();

        $this->fixture->env->mailer->failWith = new \RuntimeException('the transport refused');

        $this->fixture->kernel->notificationSender->drain(50);

        $row = $this->fixture->env->database->selectOne(
            Query::from('notification_queue')->whereNotNull('recipient_user_id')->orderBy('id'),
        );

        self::assertNotNull($row);
        self::assertGreaterThanOrEqual(1, (int) $row['attempts']);
        self::assertNotSame(QueueState::Sending->value, (string) $row['state'], 'the row was left claimed forever');
    }

    /**
     * A non-transactional message with no lawful unsubscribe mechanism is
     * held back with a named reason rather than sent headerless. Nothing
     * anywhere wrote the base URL, so every shipped install sent every
     * non-transactional message with no List-Unsubscribe header at all.
     */
    public function testANonTransactionalSendCarriesBothListUnsubscribeHeaders(): void
    {
        // The setting the host writes, which nothing on either host ever did.
        $this->fixture->kernel->settings->set(
            'notifications.unsubscribe_base_url',
            'https://example.test/unsubscribe',
        );

        // A second kernel over the same database, because the sender reads
        // the base once at boot, which is exactly how a real request reads it.
        $kernel = new \Shondalai\VidyaLMS\Core\Boot\CoreKernel($this->fixture->env->ports, [
            'verification_base_url' => 'https://example.test/verify',
        ]);

        $kernel->notificationQueue->enqueue(new QueuedNotification(
            null,
            null,
            'vidyalms.mail.submission_received',
            'assessment',
            NotificationClass::Operational,
            'vidyalms.mail.submission_received',
            'test:1',
            'once',
            hash('sha256', 'headers-test'),
            $this->fixture->env->clock->now(),
            QueueState::Pending,
            ManagementFixture::LEARNER_ID,
            'email',
            'course',
            $this->fixture->courseId,
            [
                'user_id' => ManagementFixture::LEARNER_ID,
                'course_id' => $this->fixture->courseId,
                // SubmissionReceivedTrigger freezes the submitting learner's
                // name because the recipient is normally an instructor; a
                // direct queue fixture has to preserve the same fact.
                'learner_name' => 'Lena Learner',
            ],
            'UTC',
        ));

        $kernel->notificationSender->drain(10);

        $sent = array_values(array_filter(
            $this->fixture->env->mailer->sent,
            static fn (MailMessage $message): bool => $message->templateKey === 'vidyalms.mail.submission_received',
        ));

        self::assertNotSame([], $sent, 'the operational message never reached the mailer');
        self::assertArrayHasKey('List-Unsubscribe', $sent[0]->headers);
        self::assertArrayHasKey('List-Unsubscribe-Post', $sent[0]->headers);
        self::assertSame('List-Unsubscribe=One-Click', $sent[0]->headers['List-Unsubscribe-Post']);
        // The whole map, from the one class that owns it, and not two
        // headers assembled at the call site.
        self::assertSame('auto-generated', $sent[0]->headers['Auto-Submitted']);
        self::assertSame('bulk', $sent[0]->headers['Precedence']);
        self::assertArrayHasKey('Feedback-ID', $sent[0]->headers);
    }

    /**
     * @param array<string, mixed> $schedule
     */
    private function rule(string $kind, ?string $anchorKey, array $schedule): void
    {
        // The fixture's own enrolment already fired the seeded rule, so the
        // queue is cleared first: what these tests are about is the schedule
        // this rule declares and not the fixture's setup.
        foreach ($this->fixture->env->database->selectAll(Query::from('notification_queue')->select(['id'])) as $row) {
            $this->fixture->env->database->delete('notification_queue', ['id' => (int) $row['id']]);
        }

        $this->fixture->kernel->notificationRules->save(new NotificationRule(
            null,
            'vidyalms.mail.enrolled',
            'vidyalms.mail.enrolled',
            'learner',
            'course_progress',
            'vidyalms.mail.enrolled',
            ['email'],
            $kind,
            $anchorKey,
            $schedule,
            [],
            [],
            null,
            [],
            null,
            null,
            NotificationRule::STATE_ENABLED,
        ));
    }

    private function intentRunAt(): ?string
    {
        $row = $this->fixture->env->database->selectOne(
            Query::from('notification_queue')
                ->select(['run_at'])
                ->where('state', '=', QueueState::PendingFanout->value)
                ->orderBy('id'),
        );

        return $row === null ? null : (string) $row['run_at'];
    }

    private function enrol(): void
    {
        $enrolment = $this->fixture->env->database->selectOne(
            Query::from('enrolments')
                ->where('course_id', '=', $this->fixture->courseId)
                ->where('user_id', '=', ManagementFixture::LEARNER_ID),
        );

        self::assertNotNull($enrolment);

        $this->fixture->kernel->events->dispatch(new LearnerEnrolled(
            $this->fixture->env->clock->now(),
            (int) $enrolment['id'],
            ManagementFixture::LEARNER_ID,
            $this->fixture->courseId,
            (int) $enrolment['entitlement_id'],
        ));
    }
}
