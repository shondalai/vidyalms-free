<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationConsentRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationPreferenceRepository as Prefs;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Notification\Feedback\DeliveryFeedback;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * Consent has to be givable, an unsubscribe has to be honest about what it
 * did, and an unsubscribe reported by the sending provider is the same act
 * as one pressed on our own page.
 */
final class ConsentAndUnsubscribeDefectsTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-04-01 10:00:00');
    }

    /**
     * Withdrawal must be as easy as giving, and giving has to be possible at
     * all. Nothing in the product ever wrote a granted row, so a category
     * set to never and back stayed withdrawn and the consent policy denied
     * it permanently with no path back.
     */
    public function testAWithdrawnCategoryCanBeGrantedAgain(): void
    {
        $consents = $this->fixture->kernel->notificationConsents;
        $centre = $this->fixture->kernel->notificationPreferenceCentre;
        $learner = $this->fixture->learner();

        $centre->put($learner, [[
            'category_key' => 'course_progress',
            'channel_key' => 'email',
            'frequency' => Prefs::FREQUENCY_NEVER,
        ]]);

        self::assertSame(
            SqlNotificationConsentRepository::STATE_WITHDRAWN,
            $consents->currentState(ManagementFixture::LEARNER_ID, 'course_progress'),
        );

        $centre->put($learner, [[
            'category_key' => 'course_progress',
            'channel_key' => 'email',
            'frequency' => Prefs::FREQUENCY_IMMEDIATE,
        ]]);

        self::assertSame(
            SqlNotificationConsentRepository::STATE_GRANTED,
            $consents->currentState(ManagementFixture::LEARNER_ID, 'course_progress'),
            'a withdrawn category could never be granted again',
        );

        // Append-only: the withdrawal is still on the ledger beside the grant,
        // because a deleted row is indistinguishable from never having
        // consented.
        self::assertGreaterThanOrEqual(2, $this->fixture->env->database->count(
            Query::from('notification_consents')->where('user_id', '=', ManagementFixture::LEARNER_ID),
        ));
    }

    /**
     * An empty target list wrote no preference row, appended a withdrawal
     * anyway and told the caller it had worked. The ledger is the table an
     * audit reads, so it may not disagree with what actually happened.
     */
    public function testAnUnsubscribeNamingNoUsableChannelIsRefused(): void
    {
        $token = $this->fixture->kernel->unsubscribeTokens->issue(ManagementFixture::LEARNER_ID, 'course_progress');

        try {
            $this->fixture->kernel->unsubscribe->execute($token, []);

            self::fail('an empty channel list answered unsubscribed:true');
        } catch (ValidationException) {
            // Expected.
        }

        self::assertSame(0, $this->fixture->env->database->count(Query::from('notification_consents')));
        self::assertSame(0, $this->fixture->env->database->count(Query::from('notification_preferences')));
    }

    /**
     * The landing page lists the categories the learner really does keep
     * receiving. Both arms of the class test kept the category, so it listed
     * every other one including ones already unsubscribed from.
     */
    public function testTheLandingPageListsOnlyTheCategoriesThatBypassPreference(): void
    {
        $token = $this->fixture->kernel->unsubscribeTokens->issue(ManagementFixture::LEARNER_ID, 'course_progress');
        $answer = $this->fixture->kernel->unsubscribe->execute($token, ['email']);

        $kept = $answer['still_receiving'];

        self::assertNotContains('course_progress', $kept);
        // credential carries the certificate mail, which is transactional and
        // bypasses preference: a category opt-out may not silence it.
        self::assertContains('credential', $kept);
        // assessment carries an operational message, which does not bypass
        // preference, so it is not something the learner is promised.
        self::assertNotContains('assessment', $kept);
    }

    /**
     * A learner who pressed unsubscribe in their mail client on an ESP-sent
     * stream kept receiving the category: the type was parsed, correlated
     * and dropped.
     */
    public function testAnEspReportedUnsubscribeWritesAPreferenceAndAConsentRowAndNoSuppression(): void
    {
        $queueId = $this->queueRow('provider-message-1');

        $honoured = $this->fixture->kernel->recordDeliveryFeedback->execute(
            'email',
            $this->arf('provider-message-1', DeliveryFeedback::TYPE_UNSUBSCRIBE),
            'message/feedback-report',
            $this->secret(),
        );

        self::assertSame(1, $honoured['unsubscribed'] ?? 0, (string) json_encode($honoured));
        self::assertSame($queueId, $honoured['correlated_queue_id']);

        self::assertSame(
            Prefs::FREQUENCY_NEVER,
            $this->fixture->kernel->notificationPreferences->frequencyFor(
                ManagementFixture::LEARNER_ID,
                'course_progress',
                'email',
            ),
        );

        self::assertSame(1, $this->fixture->env->database->count(
            Query::from('notification_consents')
                ->where('source', '=', SqlNotificationConsentRepository::SOURCE_DELIVERY_FEEDBACK),
        ));

        // A category-scoped click may not produce an address-scoped block:
        // suppression carries no class exemption, so the next certificate
        // mail would be silently dropped.
        self::assertSame(0, $this->fixture->env->database->count(Query::from('notification_suppressions')));
    }

    public function testUncorrelatedOrMismatchedFeedbackCannotSuppressOrUnsubscribeAnyone(): void
    {
        $queueId = $this->queueRow('known-opaque-tag');
        foreach (['abuse', 'opt-out'] as $type) {
            foreach ([$this->arf('vy' . $queueId, $type),
                str_replace('lena@example.test', 'victim@example.test', $this->arf('known-opaque-tag', $type))] as $body) {
                $result = $this->fixture->kernel->recordDeliveryFeedback->execute('email', $body, 'message/feedback-report', $this->secret());
                self::assertFalse($result['suppressed']);
                self::assertSame(0, $result['unsubscribed']);
                self::assertNull($result['correlated_queue_id']);
            }
        }
        self::assertSame(0, $this->fixture->env->database->count(Query::from('notification_suppressions')));
        self::assertSame(0, $this->fixture->env->database->count(Query::from('notification_consents')
            ->where('source', '=', SqlNotificationConsentRepository::SOURCE_DELIVERY_FEEDBACK)));
    }

    public function testAPendingMessageDoesNotAuthenticateFeedback(): void
    {
        $id = $this->queueRow('not-yet-sent');
        $this->fixture->env->database->update('notification_queue', ['state' => 'pending'], ['id' => $id]);
        $result = $this->fixture->kernel->recordDeliveryFeedback->execute('email', $this->arf('not-yet-sent', 'abuse'), 'message/feedback-report', $this->secret());
        self::assertFalse($result['suppressed']);
        self::assertNull($result['correlated_queue_id']);
    }

    private function queueRow(string $providerMessageId): int
    {
        $now = '2026-04-01 10:00:00';

        $id = $this->fixture->env->database->insert('notification_queue', [
            'rule_id' => null,
            'trigger_key' => 'vidyalms.mail.content_unlocked',
            'category_key' => 'course_progress',
            'class' => 'optional',
            'subject_type' => 'course',
            'subject_id' => $this->fixture->courseId,
            'recipient_user_id' => ManagementFixture::LEARNER_ID,
            'channel_key' => 'email',
            'template_key' => 'vidyalms.availability.unlocked',
            'parameters' => null,
            'event_ref' => 'test:1',
            'occurrence_key' => 'once',
            'dedupe_key' => hash('sha256', 'esp-unsubscribe'),
            'run_at' => $now,
            'state' => 'sent',
            'attempts' => 1,
            'provider_message_id' => $providerMessageId,
            'recipient_address_digest' => \Shondalai\VidyaLMS\Core\Domain\Notification\NotificationDigest::forAddress('lena@example.test'),
            'sent_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * The shared secret lives in the host's secret store behind a reference,
     * never in the provider's parameter bag, which is a table readable on
     * every shared-hosting install.
     */
    private function secret(): string
    {
        $this->fixture->env->secrets->put('env:VIDYALMS_SECRET_VIDYALMS_EMAIL_FEEDBACK_SECRET', 'shhh');
        $this->fixture->kernel->providerSettings->save(
            'Notification',
            'email',
            'site',
            0,
            [],
            1,
            'env:VIDYALMS_SECRET_VIDYALMS_EMAIL_FEEDBACK_SECRET',
        );

        return 'shhh';
    }

    private function arf(string $providerMessageId, string $type): string
    {
        $feedbackType = $type === DeliveryFeedback::TYPE_UNSUBSCRIBE ? 'opt-out' : 'abuse';

        return "Feedback-Type: {$feedbackType}\r\n"
            . "User-Agent: VidyaLMS-Test/1.0\r\n"
            . "Version: 1\r\n"
            . "Original-Rcpt-To: lena@example.test\r\n"
            . "Original-Mail-From: bounces@example.test\r\n"
            // The envelope id is what the mapper correlates on, and it is the
            // per-message tag the sender puts on the envelope for exactly
            // this reason.
            . "Original-Envelope-Id: {$providerMessageId}\r\n";
    }
}
