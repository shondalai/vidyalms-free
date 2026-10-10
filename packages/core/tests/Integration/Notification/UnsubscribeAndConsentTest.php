<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Events\LearnerEnrolled;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationPreferenceRepository as Prefs;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Mail\MailMessage;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The obligations that cannot be sold, and the asymmetries that keep them
 * from cancelling each other out.
 */
final class UnsubscribeAndConsentTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-04-01 10:00:00');
    }

    /**
     * Mail security scanners follow links, which is the whole reason the
     * one-click mechanism is a POST. A GET that unsubscribed would turn a
     * scanner into an opt-out.
     */
    public function testTheLandingPageRendersAndActsOnNothing(): void
    {
        $token = $this->token();

        $response = $this->fixture->call('GET', '/notifications/unsubscribe/' . $token, Principal::guest());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame('course_progress', $response->data['category_key']);
        self::assertFalse($response->data['already_unsubscribed']);

        self::assertSame(0, $this->fixture->env->database->count(Query::from('notification_preferences')));
        self::assertSame(0, $this->fixture->env->database->count(Query::from('notification_consents')));
    }

    /**
     * A category-scoped click may not produce an address-scoped block: the
     * next certificate mail is transactional and something a site may be
     * obliged to deliver.
     */
    public function testThePostWritesAPreferenceAndAConsentAndNeverASuppression(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/notifications/unsubscribe/' . $this->token(),
            Principal::guest(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertTrue($response->data['unsubscribed']);
        self::assertTrue($response->data['consent_recorded']);
        self::assertFalse($response->data['suppression_written']);

        self::assertSame(1, $this->fixture->env->database->count(
            Query::from('notification_preferences')->where('frequency', '=', Prefs::FREQUENCY_NEVER),
        ));
        self::assertSame(1, $this->fixture->env->database->count(
            Query::from('notification_consents')->where('state', '=', 'withdrawn'),
        ));
        self::assertSame(0, $this->fixture->env->database->count(Query::from('notification_suppressions')));
    }

    /**
     * The asymmetry that makes the previous rule worth having.
     */
    public function testATransactionalMessageStillSendsAfterAnUnsubscribe(): void
    {
        $this->fixture->call('POST', '/notifications/unsubscribe/' . $this->token(), Principal::guest());

        $enrolment = $this->enrolment();
        $this->fixture->kernel->events->dispatch(new LearnerEnrolled(
            $this->fixture->env->clock->now(),
            (int) $enrolment['id'],
            ManagementFixture::LEARNER_ID,
            $this->fixture->courseId,
            (int) $enrolment['entitlement_id'],
        ));

        $this->fixture->kernel->notificationFanout->run();
        $this->fixture->kernel->notificationSender->drain(10);

        self::assertSame(1, \count(array_filter(
            $this->fixture->env->mailer->sent,
            static fn (MailMessage $message): bool => $message->templateKey === 'vidyalms.mail.enrolled',
        )));
    }

    public function testAnUnknownTokenIsNotFound(): void
    {
        $response = $this->fixture->call('GET', '/notifications/unsubscribe/nonsense', Principal::guest());

        self::assertSame(404, $response->status);
    }

    /**
     * The two unauthenticated surfaces carry the header, because a name
     * against a completion is personal data and a token space is a thing to
     * walk.
     */
    public function testTheUnsubscribePageIsServedNoindex(): void
    {
        $response = $this->fixture->call('GET', '/notifications/unsubscribe/' . $this->token(), Principal::guest());

        self::assertArrayHasKey('X-Robots-Tag', $response->headers);
    }

    public function testThePreferenceCentreIsTheCallersOwnAndRefusesAGuest(): void
    {
        $guest = $this->fixture->call('GET', '/me/notification-preferences', Principal::guest());
        self::assertSame(401, $guest->status);

        $mine = $this->fixture->call('GET', '/me/notification-preferences', $this->fixture->learner());
        self::assertSame(200, $mine->status);
        self::assertNotNull($mine->data);
        self::assertNotSame([], $mine->data['rows']);
    }

    public function testSettingACategoryToNeverWritesAConsentRowToo(): void
    {
        $response = $this->fixture->call(
            'PUT',
            '/me/notification-preferences',
            $this->fixture->learner(),
            ['rows' => [[
                'category_key' => 'course_progress',
                'channel_key' => 'email',
                'frequency' => Prefs::FREQUENCY_NEVER,
            ]]],
        );

        self::assertSame(200, $response->status);
        self::assertSame(1, $this->fixture->env->database->count(
            Query::from('notification_consents')->where('source', '=', 'preference_centre'),
        ));
    }

    public function testAnUnknownCategoryIsRefusedAndNothingIsWritten(): void
    {
        $response = $this->fixture->call(
            'PUT',
            '/me/notification-preferences',
            $this->fixture->learner(),
            ['rows' => [[
                'category_key' => 'acme.newsletter',
                'channel_key' => 'email',
                'frequency' => Prefs::FREQUENCY_NEVER,
            ]]],
        );

        self::assertSame(422, $response->status);
        self::assertSame(0, $this->fixture->env->database->count(Query::from('notification_preferences')));
    }

    /**
     * An objection to direct marketing admits no balancing, so the matrix
     * refuses at save rather than discouraging in the form.
     */
    public function testAForcedStateOnAnOptionalCategoryIsRefused(): void
    {
        // course_progress carries an enrolment mail (transactional) and a
        // drip unlock notice (optional) under one key, which is the design's
        // own catalogue. First-declarer-wins read the category as
        // transactional and let an administrator force it, which forces the
        // optional message inside it too: the Article 21(3) bypass, which
        // admits no balancing. The category answers to the most restrictive
        // class any of its declarers carries.
        $response = $this->fixture->call(
            'PUT',
            '/manage/notifications/policies',
            $this->fixture->manager(),
            ['cells' => [[
                'category_key' => 'course_progress',
                'channel_key' => 'email',
                'state' => Prefs::POLICY_FORCED,
            ]]],
        );

        self::assertSame(422, $response->status);

        $matrix = $this->fixture->call('GET', '/manage/notifications/policies', $this->fixture->manager());
        self::assertNotNull($matrix->data);
        $seen = [];

        foreach ($matrix->data['cells'] as $cell) {
            $seen[$cell['category_key']] = $cell['allowed_states'];
        }

        self::assertNotContains(Prefs::POLICY_FORCED, $seen['course_progress']);
        // credential carries only the certificate mail, which is
        // transactional, so forcing it stays legitimate.
        self::assertContains(Prefs::POLICY_FORCED, $seen['credential']);
    }

    /**
     * A category every declarer agrees is transactional may still be forced,
     * because the refusal is about the class and not about the name.
     */
    public function testAForcedStateOnATransactionalCategoryIsAccepted(): void
    {
        $response = $this->fixture->call(
            'PUT',
            '/manage/notifications/policies',
            $this->fixture->manager(),
            ['cells' => [[
                'category_key' => 'credential',
                'channel_key' => 'email',
                'state' => Prefs::POLICY_FORCED,
            ]]],
        );

        self::assertSame(200, $response->status);
    }

    private function token(): string
    {
        return $this->fixture->kernel->unsubscribeTokens->issue(
            ManagementFixture::LEARNER_ID,
            'course_progress',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function enrolment(): array
    {
        $row = $this->fixture->env->database->selectOne(
            Query::from('enrolments')
                ->where('course_id', '=', $this->fixture->courseId)
                ->where('user_id', '=', ManagementFixture::LEARNER_ID),
        );

        self::assertNotNull($row);

        return $row;
    }
}
