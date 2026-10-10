<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\IdempotencyStore;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Provider\Notification\EmailNotificationChannel;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\RenderedMessage;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprintCatalogue;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TemplateBlueprintRegistration;

/** A policy-compliant, self-only delivery of synthetic authoring content. */
final class NotificationTemplateTestDelivery
{
    public const MAX_SENDS_PER_HOUR = 5;

    private const RATE_SCOPE_PREFIX = 'notification.template.test.rate';
    private const TEST_SUBJECT_PREFIX = '[Test] ';
    private const MAX_ORIGINAL_SUBJECT_CHARACTERS = 248;

    public function __construct(
        private readonly NotificationTemplateCompiler $compiler,
        private readonly NotificationTemplateBlueprintCatalogue $blueprints,
        private readonly NotificationSender $sender,
        private readonly UsersPort $users,
        private readonly IdempotencyStore $rateBudget,
        private readonly ClockPort $clock,
        private readonly AccessGuard $guard,
    ) {
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array{
     *     status: 'sent'|'failed',
     *     outcome: string,
     *     recipient_email: string,
     *     provider_message_id: ?string,
     *     error: ?string,
     *     degraded: bool
     * }
     */
    public function send(
        Principal $principal,
        string $templateKey,
        string $locale,
        string $channelKey,
        array $source,
    ): array {
        $this->guard->requireAny($principal, Capability::MANAGE, Capability::ADMIN);

        if ($channelKey !== EmailNotificationChannel::KEY) {
            throw ValidationException::forField('channel_key', 'Test delivery is available only for email.');
        }

        $locale = NotificationTemplateLocale::canonicalize($locale);
        $registration = $this->registration($templateKey);
        $account = $this->users->findById($principal->userId);

        if ($account === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('User', $principal->userId);
        }

        if (trim($account->email) === '') {
            throw ConflictException::with('The acting account has no primary email address.', [
                'outcome' => 'failed',
                'reason' => 'primary_email_missing',
            ]);
        }

        $validatedSource = TemplateSource::fromArray($source);

        // Invalid authoring content never consumes a send slot. This first
        // pass uses only provider-owned example values and has no side effect.
        $this->compiler->compile(
            $templateKey,
            $validatedSource,
            $channelKey,
            TemplateRenderContext::synthetic($registration->blueprint->syntheticValues, $locale),
        );

        [$window, $slot] = $this->consumeRateBudget($principal->userId);
        $eventReference = sprintf('notification-template-test:%d:%s:%d', $principal->userId, $window, $slot);
        $notification = new Notification(
            $principal->userId,
            $templateKey,
            $registration->blueprint->syntheticValues,
            $eventReference,
            $registration->trigger->messageClass(),
            $registration->trigger->categoryKey(),
            'test',
        );

        $result = $this->sender->deliverTest(
            $notification,
            $channelKey,
            'UTC',
            function (
                array $headers,
                ?string $unsubscribeUrl,
            ) use (
                $registration,
                $validatedSource,
                $templateKey,
                $channelKey,
                $locale,
            ): RenderedMessage {
                $values = $registration->blueprint->syntheticValues;

                if ($unsubscribeUrl !== null) {
                    $values['unsubscribe.url'] = $unsubscribeUrl;
                }

                $compiled = $this->compiler->compile(
                    $templateKey,
                    $validatedSource,
                    $channelKey,
                    TemplateRenderContext::synthetic($values, $locale),
                );

                return new RenderedMessage(
                    self::TEST_SUBJECT_PREFIX . $this->truncateSubject($compiled->subject),
                    $compiled->bodyText,
                    $compiled->bodyHtml,
                    $compiled->bodyShort,
                    $headers,
                    null,
                    $compiled->locale,
                    $templateKey,
                    $values,
                );
            },
        );

        return [
            'status' => $result->isSent() ? 'sent' : 'failed',
            'outcome' => $result->outcome->value,
            'recipient_email' => $account->email,
            'provider_message_id' => $result->providerMessageId,
            'error' => $result->error,
            'degraded' => $result->degraded,
        ];
    }

    private function registration(string $templateKey): TemplateBlueprintRegistration
    {
        try {
            return $this->blueprints->forTemplate($templateKey);
        } catch (\InvalidArgumentException) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('NotificationTemplate', $templateKey);
        }
    }

    /** @return array{string, int} */
    private function consumeRateBudget(int $actorUserId): array
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $window = $now->format('YmdH');
        $scope = self::RATE_SCOPE_PREFIX . ':' . $actorUserId . ':' . $window;

        for ($slot = 1; $slot <= self::MAX_SENDS_PER_HOUR; ++$slot) {
            try {
                $replay = $this->rateBudget->claimOrReplay($scope, (string) $slot);
            } catch (ConflictException) {
                // An in-flight claim owns this slot just as surely as a
                // completed one. Try the next fixed slot; never steal it.
                continue;
            }

            if ($replay !== null) {
                continue;
            }

            // Burn the attempt before transport. A crash after a provider
            // accepted mail must not free a slot and invite another send.
            $this->rateBudget->complete($scope, (string) $slot, [
                'used_at' => $now->format(\DATE_ATOM),
                'slot' => $slot,
            ]);

            return [$window, $slot];
        }

        $windowStart = $now->setTime((int) $now->format('H'), 0, 0);
        $retryAfter = max(1, $windowStart->modify('+1 hour')->getTimestamp() - $now->getTimestamp());

        throw ConflictException::with('The notification test-send limit has been reached.', [
            'outcome' => 'rate_limited',
            'reason' => 'test_send_rate_limit',
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            'retry_after' => $retryAfter,
        ]);
    }

    private function truncateSubject(string $subject): string
    {
        $matched = preg_match_all('/./us', $subject, $characters);

        if ($matched === false) {
            return substr($subject, 0, self::MAX_ORIGINAL_SUBJECT_CHARACTERS);
        }

        return implode('', array_slice($characters[0], 0, self::MAX_ORIGINAL_SUBJECT_CHARACTERS));
    }
}
