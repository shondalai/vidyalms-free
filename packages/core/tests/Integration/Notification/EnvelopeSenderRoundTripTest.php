<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Notification\RecordDeliveryFeedback;
use Shondalai\VidyaLMS\Core\Domain\Events\LearnerEnrolled;
use Shondalai\VidyaLMS\Core\Domain\Notification\EnvelopeSender;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueueState;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Mail\MailMessage;
use Shondalai\VidyaLMS\Core\Provider\Notification\EmailNotificationChannel;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * What the queue actually hands a transport.
 *
 * The envelope sender was composed as the bare tag "vy{id}", which is not an
 * address: PHPMailer validates Sender inside preSend() and refuses the whole
 * message, so on both hosts every queued notification went failed_permanent
 * without a transport ever seeing it. Nothing here asserted the shape, which
 * is why it shipped, so the shape is asserted from the message the channel
 * was handed and from the report that comes back.
 */
final class EnvelopeSenderRoundTripTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-04-01 10:00:00');
    }

    public function testTheEnvelopeSenderIsAValidAddressCarryingTheQueueTag(): void
    {
        $this->enrolAndDrain();

        $message = $this->firstMail();
        $queueId = $this->sentRow()['id'];

        self::assertIsString($message->envelopeSender);
        self::assertNotFalse(
            filter_var($message->envelopeSender, \FILTER_VALIDATE_EMAIL),
            'the envelope sender must be an address a transport accepts, not a bare tag',
        );
        self::assertSame(
            'bounce+' . $this->sentRow()['provider_message_id'] . '@example.test',
            $message->envelopeSender,
        );
        $tag = EnvelopeSender::tagOf($message->envelopeSender);
        self::assertNotNull($tag);
        self::assertMatchesRegularExpression('/^vy' . $queueId . '-[a-f0-9]{32}$/', $tag);
    }

    public function testTheQueueRowReachesSentAndKeepsTheTagAsItsProviderMessageId(): void
    {
        $this->enrolAndDrain();

        $row = $this->sentRow();

        self::assertSame(QueueState::Sent->value, $row['state']);
        self::assertSame(EnvelopeSender::tagOf((string) $this->firstMail()->envelopeSender), $row['provider_message_id']);
    }

    /**
     * The domain and the user part are the channel's own settings, so a site
     * that sends bulk mail from a dedicated bounce domain configures a row
     * rather than editing code.
     */
    public function testTheChannelSettingsOverrideTheHostDefault(): void
    {
        $this->fixture->kernel->providerSettings->save(
            'Notification',
            EmailNotificationChannel::KEY,
            'site',
            0,
            [
                EnvelopeSender::SETTING_DOMAIN => 'bounces.example.test',
                EnvelopeSender::SETTING_PREFIX => 'verp',
            ],
        );

        $this->enrolAndDrain();

        $queueId = (int) $this->sentRow()['id'];

        self::assertSame(
            'verp+' . $this->sentRow()['provider_message_id'] . '@bounces.example.test',
            $this->firstMail()->envelopeSender,
        );
    }

    /**
     * A misconfigured bounce domain costs the site its attribution and never
     * its mail. The invalid address is refused before the channel, not by the
     * transport, which is where it used to be refused and where the refusal
     * took the message with it.
     */
    public function testAnUnusableDomainSendsWithNoEnvelopeSenderRatherThanAnInvalidOne(): void
    {
        $this->fixture->kernel->providerSettings->save(
            'Notification',
            EmailNotificationChannel::KEY,
            'site',
            0,
            [EnvelopeSender::SETTING_DOMAIN => 'localhost'],
        );

        $this->enrolAndDrain();

        self::assertNull($this->firstMail()->envelopeSender);
        self::assertSame(QueueState::Sent->value, $this->sentRow()['state']);
    }

    /**
     * The whole point of the tag: a report that names only the reverse path
     * it was sent from still finds the row that caused it.
     */
    public function testAFeedbackReportNamingOnlyTheReversePathCorrelatesToTheQueueRow(): void
    {
        $this->enrolAndDrain();

        $queueId = (int) $this->sentRow()['id'];
        $reversePath = (string) $this->firstMail()->envelopeSender;

        // The secret is a reference into the host's store, because the params
        // bag stores cleartext whatever the field declaration says.
        $this->fixture->env->secrets->put('env:VIDYALMS_SECRET_VIDYALMS_EMAIL_FEEDBACK_SECRET', 'shared-secret');
        $this->fixture->kernel->providerSettings->save(
            'Notification',
            EmailNotificationChannel::KEY,
            'site',
            0,
            [],
            null,
            'env:VIDYALMS_SECRET_VIDYALMS_EMAIL_FEEDBACK_SECRET',
        );

        $outcome = $this->fixture->kernel->recordDeliveryFeedback->execute(
            EmailNotificationChannel::KEY,
            "Feedback-Type: abuse\r\nUser-Agent: Test/1\r\nVersion: 1\r\n"
            . 'Original-Mail-From: ' . $reversePath . "\r\n"
            . "Original-Rcpt-To: lena@example.test\r\n",
            'message/feedback-report',
            'shared-secret',
        );

        self::assertSame($queueId, $outcome['correlated_queue_id']);
    }

    private function enrolAndDrain(): void
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

        $this->fixture->kernel->notificationFanout->run();
        $this->fixture->kernel->notificationSender->drain(50);
    }

    private function firstMail(): MailMessage
    {
        $sent = $this->fixture->env->mailer->sent;

        self::assertNotSame([], $sent, 'the queue handed the channel nothing');

        return $sent[0];
    }

    /**
     * @return array<string, mixed>
     */
    private function sentRow(): array
    {
        $row = $this->fixture->env->database->selectOne(
            Query::from('notification_queue')
                ->whereNotNull('recipient_user_id')
                ->orderBy('id', Query::SORT_DESC),
        );

        self::assertNotNull($row);

        return $row;
    }
}
