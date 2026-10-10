<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseEmailLogRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Notification\EmailNotificationChannel;
use Shondalai\VidyaLMS\Core\Registry\Context\SendContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\RenderedMessage;
use Shondalai\VidyaLMS\Core\Registry\Notification\SendOutcome;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The channel's contract changed with the queue: it no longer promises
 * idempotency by event reference, because the queue owns dedupe through its
 * digest and its claim. What it promises now is to report the outcome
 * truthfully, to classify permanent failure apart from transient failure, and
 * never to throw to signal a delivery failure.
 *
 * The exactly-once guarantee did not disappear with that promise; it moved,
 * and NotificationQueueTest holds it.
 */
final class EmailNotificationChannelTest extends TestCase
{
    public function testSendsOnceAndLogsTheDelivery(): void
    {
        $env = TestPorts::create();
        $env->users->add(5, 'Asha', 'asha@example.test');
        $channel = $this->channel($env);

        $result = $channel->send($this->notification(), $this->context());

        self::assertSame(SendOutcome::Sent, $result->outcome);
        self::assertCount(1, $env->mailer->sent);
        $message = $env->mailer->sent[0];
        self::assertSame('asha@example.test', $message->recipientEmail);
        self::assertSame('vidyalms.mail.enrolled', $message->templateKey);
        self::assertSame('enrolment.created:3', $message->eventReference);

        $row = $env->database->selectOne(Query::from('email_log'));
        self::assertNotNull($row);
        self::assertSame('sent', $row['status']);
        self::assertSame('2026-01-15 10:00:00', $row['sent_at']);
        self::assertSame('enrolment.created:3', $row['event_ref']);
        self::assertSame(1, (int) $row['attempt']);
    }

    /**
     * The core obligation no adapter may weaken: the plain part is handed
     * over as authored, and the rendered subject travels with it rather than
     * being reconstructed by the host.
     */
    public function testTheRenderedPartsTravelToTheMailer(): void
    {
        $env = TestPorts::create();
        $env->users->add(5, 'Asha', 'asha@example.test');
        $channel = $this->channel($env);

        $channel->send($this->notification(), $this->context(new RenderedMessage(
            'You are enrolled',
            'Hello Asha,',
            '<p>Hello Asha,</p>',
            null,
            [RenderedMessage::HEADER_AUTO_SUBMITTED => 'auto-generated'],
        )));

        $message = $env->mailer->sent[0];
        self::assertTrue($message->isRendered());
        self::assertSame('You are enrolled', $message->subject);
        self::assertSame('Hello Asha,', $message->bodyText);
        self::assertSame('<p>Hello Asha,</p>', $message->bodyHtml);
        self::assertSame('auto-generated', $message->header('auto-submitted'));
    }

    /**
     * A retry is a second row and not an upsert over the first. None of the
     * digest's three components varies between attempts, so a per-message
     * unique index would lose the evidence that a retry happened, which is
     * the one thing a per-attempt record exists to hold.
     */
    public function testASecondAttemptIsASecondRow(): void
    {
        $env = TestPorts::create();
        $env->users->add(5);
        $channel = $this->channel($env);

        $channel->send($this->notification(), $this->context(attempt: 1));
        $channel->send($this->notification(), $this->context(attempt: 2));

        self::assertCount(2, $env->mailer->sent);
        self::assertSame(2, $env->database->count(Query::from('email_log')));

        $attempts = array_map(
            static fn (array $row): int => (int) $row['attempt'],
            $env->database->selectAll(Query::from('email_log')->select(['attempt'])->orderBy('attempt')),
        );
        self::assertSame([1, 2], $attempts);
    }

    public function testADifferentOccurrenceLogsAgain(): void
    {
        $env = TestPorts::create();
        $env->users->add(5);
        $channel = $this->channel($env);

        $channel->send($this->notification('enrolment.created:3'), $this->context());
        $channel->send($this->notification('enrolment.created:4'), $this->context());

        self::assertCount(2, $env->mailer->sent);
        self::assertSame(2, $env->database->count(Query::from('email_log')));
    }

    public function testAMailerFailureMarksTheRowFailedWithoutThrowing(): void
    {
        $env = TestPorts::create();
        $env->users->add(5);
        $env->mailer->failWith = new \RuntimeException('smtp down');
        $channel = $this->channel($env);

        $result = $channel->send($this->notification(), $this->context());

        self::assertSame(SendOutcome::FailedPermanent, $result->outcome);
        self::assertCount(0, $env->mailer->sent);
        $row = $env->database->selectOne(Query::from('email_log'));
        self::assertNotNull($row);
        self::assertSame('failed', $row['status']);
        self::assertSame('smtp down', $row['error']);
        self::assertNull($row['sent_at']);
    }

    /**
     * A transient failure is classified apart from a permanent one, because
     * the queue decides whether to retry from that answer alone and a
     * poisoned row must be stepped over rather than retried forever.
     */
    public function testATransientFailureIsClassifiedAsTransient(): void
    {
        $env = TestPorts::create();
        $env->users->add(5);
        $env->mailer->failWith = new \RuntimeException('Connection timed out');
        $channel = $this->channel($env);

        $result = $channel->send($this->notification(), $this->context());

        self::assertSame(SendOutcome::FailedTransient, $result->outcome);
        self::assertTrue($result->outcome->isRetryable());
    }

    public function testAMissingUserLogsNothingAndFailsPermanently(): void
    {
        $env = TestPorts::create();
        $channel = $this->channel($env);

        $result = $channel->send($this->notification(), $this->context());

        self::assertSame(SendOutcome::FailedPermanent, $result->outcome);
        self::assertCount(0, $env->mailer->sent);
        self::assertSame(0, $env->database->count(Query::from('email_log')));
    }

    private function channel(TestPorts $env): EmailNotificationChannel
    {
        return new EmailNotificationChannel(
            $env->users,
            $env->mailer,
            new DatabaseEmailLogRepository($env->database, $env->clock),
            $env->translator,
        );
    }

    private function context(?RenderedMessage $message = null, int $attempt = 1): SendContext
    {
        return SendContext::create(
            $message ?? new RenderedMessage('You are enrolled', 'Hello,'),
            41,
            $attempt,
        );
    }

    private function notification(string $eventReference = 'enrolment.created:3'): Notification
    {
        return new Notification(5, 'vidyalms.mail.enrolled', ['course_id' => 9], $eventReference);
    }
}
