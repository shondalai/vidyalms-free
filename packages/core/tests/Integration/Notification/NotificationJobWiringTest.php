<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationJobs;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueueState;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Mail\MailMessage;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\NotificationFanoutTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\NotificationSendTask;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The trigger queues its own work.
 *
 * Wave 4a shipped both hops as handlers nothing ever queued: the intent row
 * was written correctly, the fan-out expanded it correctly and the drain sent
 * correctly, and on both hosts the queue moved only after a job row was
 * inserted by hand. Nothing here calls a use case's collaborator directly:
 * the enrolment is made through the shipped use case, and every step after it
 * runs the registered handler over the payload the scheduler was actually
 * given, so a wiring that stops queueing fails here rather than in production.
 */
final class NotificationJobWiringTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-04-01 10:00:00');

        // The fixture enrols its own learner while building the course, so
        // its intent row is drained and its jobs and mail forgotten before
        // anything here is measured.
        $this->fixture->kernel->notificationFanout->run();
        $this->fixture->kernel->notificationSender->drain(50);
        $this->fixture->env->scheduler->tasks = [];
        $this->fixture->env->mailer->sent = [];
    }

    public function testAFiredTriggerQueuesTheFanoutAndThenTheSendWithNoManualRow(): void
    {
        $this->enrolThroughTheUseCase();

        $intentId = $this->intentId();
        $fanout = $this->task(NotificationFanoutTask::TYPE);

        self::assertNotNull($fanout, 'the committed intent row queued no fan-out job');
        self::assertSame(NotificationJobs::fanoutDedupeKey($intentId), $fanout->dedupeKey);

        $this->dispatch(NotificationFanoutTask::TYPE, $fanout);

        $row = $this->recipientRow();
        self::assertNotNull($row, 'the fan-out wrote no recipient row');

        $send = $this->task(NotificationSendTask::TYPE);
        self::assertNotNull($send, 'the recipient row queued no send job');
        self::assertSame($row['dedupe_key'], $send->dedupeKey);

        $this->dispatch(NotificationSendTask::TYPE, $send);

        self::assertSame(1, $this->mailCount('vidyalms.mail.enrolled'));
    }

    /**
     * The keys are the design's, and the periodic pair is namespaced apart
     * from them. Both halves of the unnamespaced form are integers, so a
     * period bucket (the epoch over five minutes, in the millions today)
     * would eventually equal an intent row id and one job would replace the
     * other.
     */
    public function testThePeriodicKeyCannotCollideWithAPerRowWakeUp(): void
    {
        $bucket = (string) intdiv($this->fixture->env->clock->now()->getTimestamp(), 300);
        $periodic = (new NotificationFanoutTask($this->fixture->kernel->notificationFanout))
            ->periodicDedupeKey($bucket, 0);

        self::assertNotSame(NotificationJobs::fanoutDedupeKey((int) $bucket), $periodic);
    }

    /**
     * A second commit for the same occurrence writes no second intent row, so
     * it queues no second job either: the wake-up cannot outnumber the work.
     */
    public function testAReplayQueuesNoSecondFanout(): void
    {
        $this->enrolThroughTheUseCase();
        $first = \count($this->tasks(NotificationFanoutTask::TYPE));

        $this->enrolThroughTheUseCase();

        self::assertSame(1, $first);
        self::assertCount($first, $this->tasks(NotificationFanoutTask::TYPE));
    }

    private function enrolThroughTheUseCase(): void
    {
        $userId = 777;
        $this->fixture->env->users->add($userId, 'Ada Okafor', 'ada@example.test');
        $database = $this->fixture->env->database;
        $existing = $database->selectOne(
            Query::from('entitlements')
                ->where('user_id', '=', $userId)
                ->where('course_id', '=', $this->fixture->courseId),
        );

        $entitlementId = $existing === null
            ? $database->insert('entitlements', [
                'user_id' => $userId,
                'course_id' => $this->fixture->courseId,
                'source' => 'manual',
                'status' => 'active',
                'starts_at' => '2026-04-01 10:00:00',
                'created_at' => '2026-04-01 10:00:00',
                'updated_at' => '2026-04-01 10:00:00',
            ])
            : (int) $existing['id'];

        $this->fixture->kernel->enrolLearner->execute($userId, $this->fixture->courseId, $entitlementId);
    }

    /**
     * The newest intent row, because the fixture's own enrolment left an
     * older one waiting: this asserts about the enrolment this test made.
     */
    private function intentId(): int
    {
        $row = $this->fixture->env->database->selectOne(
            Query::from('notification_queue')
                ->where('state', '=', QueueState::PendingFanout->value)
                ->orderBy('id', Query::SORT_DESC),
        );

        self::assertNotNull($row, 'the enrolment wrote no intent row');

        return (int) $row['id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recipientRow(): ?array
    {
        return $this->fixture->env->database->selectOne(
            Query::from('notification_queue')
                ->whereNotNull('recipient_user_id')
                ->orderBy('id', Query::SORT_DESC),
        );
    }

    private function dispatch(string $type, ScheduledTask $task): void
    {
        $this->fixture->kernel->registries->scheduledTasks->get($type)->run(new ScheduledTaskRun(
            1,
            $task->payload,
            Principal::guest(),
        ));
    }

    private function task(string $type): ?ScheduledTask
    {
        return $this->tasks($type)[0] ?? null;
    }

    /**
     * @return list<ScheduledTask>
     */
    private function tasks(string $type): array
    {
        return array_values(array_filter(
            $this->fixture->env->scheduler->tasks,
            static fn (ScheduledTask $task): bool => $task->type === $type,
        ));
    }

    private function mailCount(string $templateKey): int
    {
        return \count(array_filter(
            $this->fixture->env->mailer->sent,
            static fn (MailMessage $message): bool => $message->templateKey === $templateKey,
        ));
    }
}
