<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationSender;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationTemplateCompiler;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationTemplateTestDelivery;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationDigest;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationConsentRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationPreferenceRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationSuppressionRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlProviderSettingsRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Notification\Policy\QuietHoursPolicy;
use Shondalai\VidyaLMS\Core\Provider\Token\UnsubscribeTokenResolver;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\SuppressionReason;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprintCatalogue;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class NotificationTemplateTestDeliveryTest extends TestCase
{
    private const ACTOR_ID = 71;
    private const OPTIONAL_TEMPLATE = 'vidyalms.availability.unlocked';

    private TestPorts $env;
    private CoreKernel $kernel;
    private NotificationTemplateBlueprintCatalogue $blueprints;
    private NotificationTemplateTestDelivery $delivery;

    protected function setUp(): void
    {
        $this->boot('https://example.test/notifications/unsubscribe');
    }

    public function testDeliveryUsesOnlyTheActorsPrimaryEmailAndNeverTransportsTheSampleUnsubscribeUrl(): void
    {
        $source = $this->source();
        $source['subject'] = str_repeat('界', 255);
        $beforeQueue = $this->env->database->count(Query::from('notification_queue'));

        $result = $this->delivery->send(
            Principal::user(self::ACTOR_ID),
            self::OPTIONAL_TEMPLATE,
            'en-GB',
            'email',
            $source,
        );

        self::assertSame('sent', $result['status']);
        self::assertSame('sent', $result['outcome']);
        self::assertSame('author@example.test', $result['recipient_email']);
        self::assertNull($result['provider_message_id']);
        self::assertNull($result['error']);
        self::assertFalse($result['degraded']);
        self::assertCount(1, $this->env->mailer->sent);

        $mail = $this->env->mailer->sent[0];
        self::assertSame(self::ACTOR_ID, $mail->recipientUserId);
        self::assertSame('author@example.test', $mail->recipientEmail);
        self::assertNotNull($mail->subject);
        self::assertStringStartsWith('[Test] ', $mail->subject);
        self::assertSame(255, mb_strlen($mail->subject));
        self::assertStringNotContainsString(
            'https://example.invalid/notifications/unsubscribe/sample',
            (string) $mail->bodyText . (string) $mail->bodyHtml . json_encode($mail->parameters),
        );
        self::assertStringContainsString(
            'https://example.test/notifications/unsubscribe/',
            (string) $mail->bodyText,
        );
        self::assertStringContainsString(
            'https://example.test/notifications/unsubscribe/',
            (string) $mail->headers['List-Unsubscribe'],
        );
        self::assertSame('List-Unsubscribe=One-Click', $mail->headers['List-Unsubscribe-Post']);
        self::assertNull($mail->envelopeSender);
        self::assertSame($beforeQueue, $this->env->database->count(Query::from('notification_queue')));
    }

    public function testAuthorizationIsEnforcedInsideTheServiceBeforeARecipientIsRead(): void
    {
        $this->env->users->add(72, 'Unprivileged', 'unprivileged@example.test');

        try {
            $this->delivery->send(Principal::user(72), self::OPTIONAL_TEMPLATE, 'en-GB', 'email', $this->source());
            self::fail('An unprivileged actor sent a test notification.');
        } catch (NotAuthorizedException) {
            self::assertSame(0, $this->env->users->accountLookups);
            self::assertSame([], $this->env->mailer->sent);
        }
    }

    public function testDisabledChannelsAlsoBlockTestDelivery(): void
    {
        $this->kernel->manageProviderSettings->save(
            Principal::user(self::ACTOR_ID),
            'Notification',
            'email',
            'site',
            0,
            [],
            null,
            false,
        );
        $conflict = $this->captureConflict(fn () => $this->delivery->send(
            Principal::user(self::ACTOR_ID),
            self::OPTIONAL_TEMPLATE,
            'en-GB',
            'email',
            $this->source(),
        ));
        self::assertSame(SuppressionReason::CHANNEL_DISABLED, $conflict->extensions['reason']);
        self::assertSame([], $this->env->mailer->sent);
    }

    public function testDeliveryCanonicalizesTheAuthoredLocaleBeforeCompilation(): void
    {
        $result = $this->delivery->send(
            Principal::user(self::ACTOR_ID),
            self::OPTIONAL_TEMPLATE,
            'FR-ca-u-CA-gregory',
            'email',
            $this->source(),
        );

        self::assertSame('sent', $result['status']);
        self::assertCount(1, $this->env->mailer->sent);
        self::assertSame('fr-CA-u-ca-gregory', $this->env->mailer->sent[0]->locale);
    }

    public function testInvalidSourceAndUnsupportedChannelConsumeNoRateBudget(): void
    {
        $invalid = $this->source();
        $invalid['subject'] = '';

        try {
            $this->delivery->send(
                Principal::user(self::ACTOR_ID),
                self::OPTIONAL_TEMPLATE,
                'en-GB',
                'email',
                $invalid,
            );
            self::fail('Invalid source was delivered.');
        } catch (ValidationException) {
            self::assertSame(0, $this->env->database->count(Query::from('idempotency_keys')));
        }

        try {
            $this->delivery->send(
                Principal::user(self::ACTOR_ID),
                self::OPTIONAL_TEMPLATE,
                'en-GB',
                'sms',
                $this->source(),
            );
            self::fail('A test was sent over a non-email channel.');
        } catch (ValidationException) {
            self::assertSame(0, $this->env->database->count(Query::from('idempotency_keys')));
        }
    }

    public function testFiveAtomicHourlySlotsAreDurableAndTheNextHourHasANewBudget(): void
    {
        for ($attempt = 1; $attempt <= NotificationTemplateTestDelivery::MAX_SENDS_PER_HOUR; ++$attempt) {
            self::assertSame('sent', $this->send()['status']);
        }

        $limited = $this->captureConflict(fn (): array => $this->send());
        self::assertSame('rate_limited', $limited->extensions['outcome']);
        self::assertSame('test_send_rate_limit', $limited->extensions['reason']);
        self::assertGreaterThan(0, $limited->extensions['retry_after']);
        self::assertCount(5, $this->env->mailer->sent);
        self::assertSame(5, $this->env->database->count(
            Query::from('idempotency_keys')->where('status', '=', 'completed'),
        ));

        $this->env->clock->set('2026-01-15 11:00:00');
        self::assertSame('sent', $this->send()['status']);
        self::assertCount(6, $this->env->mailer->sent);
    }

    public function testSuppressedAddressIsRefusedWithoutQueueOrCounterMutation(): void
    {
        $this->kernel->notificationSuppressions->suppress(
            'email',
            NotificationDigest::forAddress('author@example.test'),
            SqlNotificationSuppressionRepository::REASON_HARD_BOUNCE,
        );

        $conflict = $this->captureConflict(fn (): array => $this->send());
        self::assertSame('suppressed', $conflict->extensions['outcome']);
        self::assertSame(SuppressionReason::SUPPRESSED_ADDRESS, $conflict->extensions['reason']);
        self::assertSame([], $this->env->mailer->sent);
        self::assertSame(0, $this->env->database->count(Query::from('notification_queue')));
    }

    public function testWithdrawnConsentIsRefusedForTheTriggersImmutableOptionalClass(): void
    {
        $this->kernel->notificationConsents->append(
            self::ACTOR_ID,
            'course_progress',
            SqlNotificationConsentRepository::STATE_WITHDRAWN,
            SqlNotificationConsentRepository::BASIS_CONSENT,
            SqlNotificationConsentRepository::SOURCE_PREFERENCE_CENTRE,
        );

        $conflict = $this->captureConflict(fn (): array => $this->send());
        self::assertSame(SuppressionReason::CONSENT_WITHDRAWN, $conflict->extensions['reason']);
        self::assertSame([], $this->env->mailer->sent);
    }

    public function testNeverPreferenceIsRefusedForTheTriggersImmutableOptionalClass(): void
    {
        $this->kernel->notificationPreferences->setPreference(
            self::ACTOR_ID,
            'course_progress',
            'email',
            SqlNotificationPreferenceRepository::FREQUENCY_NEVER,
        );

        $conflict = $this->captureConflict(fn (): array => $this->send());
        self::assertSame(SuppressionReason::PREFERENCE, $conflict->extensions['reason']);
        self::assertSame([], $this->env->mailer->sent);
    }

    public function testUnconfirmedChannelAddressIsRefused(): void
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->env->database->insert('notification_channel_addresses', [
            'user_id' => self::ACTOR_ID,
            'channel_key' => 'email',
            'address_digest' => NotificationDigest::forAddress('author@example.test'),
            'confirmation_sent_at' => $now,
            'confirmed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $conflict = $this->captureConflict(fn (): array => $this->send());
        self::assertSame(SuppressionReason::CHANNEL_UNCONFIRMED, $conflict->extensions['reason']);
        self::assertSame([], $this->env->mailer->sent);
    }

    public function testQuietHoursReturnsTheExactDeferralWithoutSending(): void
    {
        $this->kernel->providerSettings->save(
            NotificationSender::REGISTRY_POLICY,
            QuietHoursPolicy::KEY,
            SqlProviderSettingsRepository::SCOPE_SITE,
            0,
            [
                QuietHoursPolicy::SETTING_ENABLED => true,
                QuietHoursPolicy::SETTING_START => '09:00',
                QuietHoursPolicy::SETTING_END => '17:00',
            ],
        );

        $conflict = $this->captureConflict(fn (): array => $this->send());
        self::assertSame('deferred', $conflict->extensions['outcome']);
        self::assertSame('quiet_hours', $conflict->extensions['reason']);
        self::assertSame('2026-01-15T17:00:00+00:00', $conflict->extensions['retry_at']);
        self::assertSame([], $this->env->mailer->sent);
    }

    public function testMailtoOnlyCannotSendAStructuredNonTransactionalBody(): void
    {
        $this->boot('', 'mailto:unsubscribe@example.test');

        $conflict = $this->captureConflict(fn (): array => $this->send());
        self::assertSame('suppressed', $conflict->extensions['outcome']);
        self::assertSame(SuppressionReason::UNSUBSCRIBE_UNAVAILABLE, $conflict->extensions['reason']);
        self::assertSame([], $this->env->mailer->sent);
    }

    public function testTransportFailureIsACompletedNonRetryResultAndConsumesItsSlot(): void
    {
        $this->env->mailer->failWith = new \RuntimeException('connection timed out after provider hand-off');

        $result = $this->send();

        self::assertSame('failed', $result['status']);
        self::assertSame('failed_transient', $result['outcome']);
        self::assertNotNull($result['error']);
        self::assertSame(1, $this->env->database->count(
            Query::from('idempotency_keys')->where('status', '=', 'completed'),
        ));
        self::assertSame(0, $this->env->database->count(Query::from('notification_queue')));
    }

    private function boot(string $unsubscribeBaseUrl, ?string $unsubscribeMailto = null): void
    {
        $this->env = TestPorts::create();
        $this->env->clock->set('2026-01-15 10:00:00');
        $this->env->authorization->grant(self::ACTOR_ID, Capability::MANAGE);
        $this->env->users->add(self::ACTOR_ID, 'Template Author', 'author@example.test');

        if ($unsubscribeMailto !== null) {
            $settings = new Settings(new DatabaseSettingsRepository($this->env->database, $this->env->clock));
            $settings->set(UnsubscribeTokenResolver::SETTING_MAILTO, $unsubscribeMailto);
        }

        $this->kernel = new CoreKernel($this->env->ports, [
            'unsubscribe_base_url' => $unsubscribeBaseUrl,
            'verification_base_url' => 'https://example.test/verify',
            'envelope_domain' => 'example.test',
        ]);
        $this->blueprints = new NotificationTemplateBlueprintCatalogue(
            $this->kernel->registries->notificationTriggers,
        );
        $compiler = new NotificationTemplateCompiler(
            $this->blueprints,
            $this->kernel->registries->tokenResolvers,
            $this->kernel->registries->notificationChannels,
        );
        $this->delivery = new NotificationTemplateTestDelivery(
            $compiler,
            $this->blueprints,
            $this->kernel->notificationSender,
            $this->env->users,
            $this->kernel->idempotencyStore,
            $this->env->clock,
            new AccessGuard($this->env->authorization),
        );
    }

    /** @return array<string, mixed> */
    private function source(): array
    {
        return $this->blueprints->forTemplate(self::OPTIONAL_TEMPLATE)->blueprint->source;
    }

    /** @return array<string, mixed> */
    private function send(): array
    {
        return $this->delivery->send(
            Principal::user(self::ACTOR_ID),
            self::OPTIONAL_TEMPLATE,
            'en-GB',
            'email',
            $this->source(),
        );
    }

    /** @param callable(): mixed $operation */
    private function captureConflict(callable $operation): ConflictException
    {
        try {
            $operation();
        } catch (ConflictException $conflict) {
            return $conflict;
        }

        self::fail('Expected a conflict.');
    }
}
