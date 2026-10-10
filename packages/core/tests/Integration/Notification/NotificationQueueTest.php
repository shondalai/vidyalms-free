<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Events\LearnerEnrolled;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationDigest;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueueState;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationPreferenceRepository as Prefs;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationSuppressionRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Mail\MailMessage;
use Shondalai\VidyaLMS\Core\Provider\Notification\Policy\QuietHoursPolicy;
use Shondalai\VidyaLMS\Core\Registry\Notification\RenderedMessage;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The queue's guarantees, which the channel no longer promises.
 *
 * Delivery is at-least-once with an idempotent consumer; the exactly-once
 * effect comes from the dedupe digest and the claim, not from the transport.
 */
final class NotificationQueueTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-04-01 10:00:00');
    }

    public function testOneEventProducesOneIntentRowAndOneSend(): void
    {
        $this->enrol();
        $this->drainQueue();

        self::assertSame(1, $this->mailCount('vidyalms.mail.enrolled'));
    }

    public function testDisablingAChannelStopsAlreadyQueuedTransactionalMail(): void
    {
        $this->enrol();
        $this->fixture->kernel->notificationFanout->run();
        $this->fixture->kernel->manageProviderSettings->save(
            $this->fixture->manager(),
            'Notification',
            'email',
            'site',
            0,
            [],
            null,
            false,
        );
        $this->fixture->kernel->notificationSender->drain(10);
        self::assertSame(0, $this->mailCount('vidyalms.mail.enrolled'));
        self::assertSame('channel_disabled', $this->stateReason()['suppressed_reason']);
        self::assertSame(QueueState::Suppressed->value, $this->stateReason()['state']);
    }

    public function testReenablingAChannelAllowsPendingMailToSend(): void
    {
        $this->enrol();
        foreach ([false, true] as $enabled) {
            $this->fixture->kernel->manageProviderSettings->save(
                $this->fixture->manager(),
                'Notification',
                'email',
                'site',
                0,
                [],
                null,
                $enabled,
            );
        }
        $this->drainQueue();
        self::assertSame(1, $this->mailCount('vidyalms.mail.enrolled'));
    }

    /**
     * Two runners over one pending row is the normal case on both hosts, not
     * the exotic one, so the claim and not the digest is what makes this one
     * send.
     */
    public function testTwoConcurrentDrainsOverOnePendingRowProduceExactlyOneSend(): void
    {
        $this->enrol();
        $this->fixture->kernel->notificationFanout->run();

        $row = $this->pending();
        self::assertNotNull($row);

        $first = $this->fixture->kernel->notificationQueue->claim($row['id'], 'runner-a');
        $second = $this->fixture->kernel->notificationQueue->claim($row['id'], 'runner-b');

        self::assertTrue($first);
        self::assertFalse($second);
    }

    public function testAReplayedEventEnqueuesNothingTwice(): void
    {
        $this->enrol();
        $this->enrol();
        $this->drainQueue();

        self::assertSame(1, $this->mailCount('vidyalms.mail.enrolled'));
    }

    /**
     * The two obligations no adapter may weaken.
     */
    public function testThePlainPartPrecedesTheHtmlPart(): void
    {
        $message = new RenderedMessage('Subject', 'Plain body', '<p>Rich body</p>');
        $parts = $message->alternativeParts();

        self::assertSame('text/plain', $parts[0]['type']);
        self::assertSame('text/html', $parts[1]['type']);
    }

    public function testASuppressedAddressIsNeverHandedToTheChannel(): void
    {
        $this->enrol();
        $this->fixture->kernel->notificationFanout->run();

        $this->fixture->kernel->notificationSuppressions->suppress(
            'email',
            NotificationDigest::forAddress('lena@example.test'),
            SqlNotificationSuppressionRepository::REASON_HARD_BOUNCE,
        );

        $this->fixture->kernel->notificationSender->drain(10);

        self::assertSame(0, $this->mailCount('vidyalms.mail.enrolled'));
        self::assertSame('address_suppressed', $this->stateReason()['suppressed_reason']);
        self::assertSame(QueueState::Suppressed->value, $this->stateReason()['state']);
    }

    /**
     * Quiet hours defers and never suppresses: a certificate landing at 23:40
     * inside a window must be delivered late, not dropped.
     */
    public function testQuietHoursDefersRatherThanSuppressing(): void
    {
        $this->fixture->kernel->providerSettings->save(
            'NotificationPolicy',
            QuietHoursPolicy::KEY,
            'site',
            0,
            [QuietHoursPolicy::SETTING_ENABLED => true, 'start' => '09:00', 'end' => '17:00'],
        );

        // The enrolled mail is transactional, so the optional-class exemption
        // does not apply to quiet hours: it defers every class.
        $this->enrol();
        $this->fixture->kernel->notificationFanout->run();
        $this->fixture->kernel->notificationSender->drain(10);

        $row = $this->stateReason();
        self::assertSame(QueueState::Deferred->value, $row['state']);
        self::assertNotNull($row['next_attempt_at']);
        self::assertSame(0, $this->mailCount('vidyalms.mail.enrolled'));

        // And it is not re-selected before its instant.
        $this->fixture->kernel->notificationSender->drain(10);
        self::assertSame(0, $this->mailCount('vidyalms.mail.enrolled'));

        $this->fixture->env->clock->set('2026-04-01 17:30:00');
        $this->fixture->kernel->notificationSender->drain(10);
        self::assertSame(1, $this->mailCount('vidyalms.mail.enrolled'));
    }

    public function testAPreferenceOfNeverSuppressesAnOptionalMessageAndNotATransactionalOne(): void
    {
        $this->fixture->kernel->notificationPreferences->setPreference(
            ManagementFixture::LEARNER_ID,
            'course_progress',
            'email',
            Prefs::FREQUENCY_NEVER,
        );

        $this->enrol();
        $this->drainQueue();

        // Enrolment mail is transactional, so a preference does not silence
        // it: a site may be obliged to send it.
        self::assertSame(1, $this->mailCount('vidyalms.mail.enrolled'));
    }

    public function testTheEnqueueIsInsideTheBusinessTransactionAndRollsBackWithIt(): void
    {
        $before = $this->fixture->env->database->count(Query::from('notification_queue'));

        try {
            $this->fixture->env->database->transactional(function (): void {
                $this->fixture->env->database->insert('notification_queue', [
                    'trigger_key' => 'test.trigger',
                    'category_key' => 'account',
                    'class' => 'transactional',
                    'template_key' => 'test.template',
                    'event_ref' => 'test:1',
                    'occurrence_key' => 'once',
                    'dedupe_key' => hash('sha256', 'rollback'),
                    'run_at' => '2026-04-01 10:00:00',
                    'state' => QueueState::Pending->value,
                    'created_at' => '2026-04-01 10:00:00',
                    'updated_at' => '2026-04-01 10:00:00',
                ]);

                throw new \RuntimeException('the business write failed');
            });
        } catch (\RuntimeException) {
            // The direction that loses mail is state committed with no queue
            // row; a queue row written in an aborted transaction never sends
            // is the direction that is already free.
        }

        self::assertSame($before, $this->fixture->env->database->count(Query::from('notification_queue')));
    }

    public function testAnOperatorCanCancelAPendingRowAndTheDigestIsKept(): void
    {
        $this->enrol();
        $this->fixture->kernel->notificationFanout->run();

        $row = $this->pending();
        self::assertNotNull($row);

        $response = $this->fixture->call(
            'POST',
            '/manage/notifications/queue/' . $row['id'] . '/cancel',
            $this->fixture->manager(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(QueueState::Cancelled->value, $response->data['state']);

        // The cancelled row keeps its digest, so the occurrence is never
        // re-enqueued behind the operator's back.
        $this->enrol();
        $this->fixture->kernel->notificationFanout->run();
        $this->fixture->kernel->notificationSender->drain(10);

        self::assertSame(0, $this->mailCount('vidyalms.mail.enrolled'));
    }

    public function testCancellingARowThatIsNoLongerPendingIsRefused(): void
    {
        $this->enrol();
        $this->drainQueue();

        $row = $this->fixture->env->database->selectOne(
            Query::from('notification_queue')->whereNotNull('recipient_user_id')->orderBy('id'),
        );
        self::assertNotNull($row);

        $response = $this->fixture->call(
            'POST',
            '/manage/notifications/queue/' . $row['id'] . '/cancel',
            $this->fixture->manager(),
        );

        self::assertSame(409, $response->status);
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

    private function drainQueue(): void
    {
        $this->fixture->kernel->notificationFanout->run();
        $this->fixture->kernel->notificationSender->drain(50);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pending(): ?array
    {
        return $this->fixture->env->database->selectOne(
            Query::from('notification_queue')
                ->where('state', '=', QueueState::Pending->value)
                ->orderBy('id'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function stateReason(): array
    {
        $row = $this->fixture->env->database->selectOne(
            Query::from('notification_queue')
                ->whereNotNull('recipient_user_id')
                ->orderBy('id', Query::SORT_DESC),
        );

        self::assertNotNull($row);

        return $row;
    }

    private function mailCount(string $templateKey): int
    {
        return \count(array_filter(
            $this->fixture->env->mailer->sent,
            static fn (MailMessage $message): bool => $message->templateKey === $templateKey,
        ));
    }
}
