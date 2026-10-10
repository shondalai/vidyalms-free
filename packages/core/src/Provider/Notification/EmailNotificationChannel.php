<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification;

use Shondalai\VidyaLMS\Core\Domain\Notification\EnvelopeSender;
use Shondalai\VidyaLMS\Core\Domain\Platform\EmailLogRepository;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Mail\MailerPort;
use Shondalai\VidyaLMS\Core\Port\Mail\MailMessage;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\Context\SendContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationChannelInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\ReportsDeliveryFeedback;
use Shondalai\VidyaLMS\Core\Registry\Notification\SendResult;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsHtmlBody;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsListHeaders;
use Shondalai\VidyaLMS\Core\Registry\Settings\DeclaresSettings;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsDeclarationContext;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsFields;

/**
 * The free build's transactional email channel.
 *
 * It no longer owns dedupe. The queue owns that, through its digest and its
 * claim, and the email log became the per-attempt delivery record: none of
 * the log digest's three components varies between attempts, so under a
 * per-message unique index a retry after a transient failure either threw
 * inside the send path after the mail had already gone out or silently
 * upserted and lost the evidence that a retry happened, which is the one
 * thing a per-attempt record exists to hold.
 *
 * What it owns now is telling the truth about the outcome and classifying
 * permanent failure apart from transient failure, so the queue can decide
 * whether to retry. It never throws to signal a delivery failure.
 *
 * It hands the mailer a finished message rather than a template key, because
 * otherwise there are two template systems and nothing says which wins: the
 * core owns the four parts, the plain-first ordering, token resolution,
 * send-time locale, the auto-submitted stamp and the unsubscribe header pair,
 * and delegating those to per-host rendering would leave legal obligations
 * enforced by adapter goodwill and untested here.
 */
final class EmailNotificationChannel implements
    NotificationChannelInterface,
    SupportsHtmlBody,
    SupportsListHeaders,
    ReportsDeliveryFeedback,
    DeclaresSettings
{
    public const KEY = 'email';

    /**
     * The two provider settings the feedback route reads for this channel,
     * named here because this is the provider that owns them. The use case
     * that reads them names the same strings as the cross-channel convention;
     * a test asserts the two agree, so the provider stays a provider and
     * does not reach into the application layer for a constant.
     *
     * **Only the threshold is a declared params field.** The shared secret is
     * the provider's own `provider_settings.secret_ref`, resolved through
     * `ProviderSecrets` and the host's secret store, and it is deliberately
     * not offered as a params field: a `write_only` declaration masks the
     * read path and stores the value in cleartext all the same, in a table
     * that is readable on every shared-hosting install and beside a column
     * that refuses the same value by design. `ManageProviderSettings` now
     * refuses a params write under this key for every channel, so no other
     * provider can reintroduce it.
     */
    public const SETTING_FEEDBACK_SECRET = 'feedback_secret';
    public const SETTING_SOFT_BOUNCE_THRESHOLD = 'soft_bounce_threshold';
    public const DEFAULT_SOFT_BOUNCE_THRESHOLD = 5;

    /** What a transient failure looks like when the mailer says nothing else. */
    private const TRANSIENT_MARKERS = ['timeout', 'timed out', 'temporarily', 'try again', 'connection', 'greylist'];

    public function __construct(
        private readonly UsersPort $users,
        private readonly MailerPort $mailer,
        private readonly EmailLogRepository $emailLog,
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.channel.email');
    }

    public function feedbackMapperKey(): string
    {
        return 'rfc5965';
    }

    /**
     * The four rows this channel reads, so a site owner has somewhere to type
     * them.
     *
     * Every one of them was already read by the code and settable only by an
     * API call: the bounce domain and its prefix compose the envelope sender,
     * the shared secret is the entire authentication of the inbound feedback
     * route, and the threshold decides when repeated soft bounces become a
     * suppression. A field a site cannot reach is a feature nobody has.
     */
    public function settingsGroup(SettingsDeclarationContext $context): array
    {
        return [
            'key' => self::KEY,
            'label' => $this->translator->translate('vidyalms.channel.email'),
            'description' => $this->translator->translate('vidyalms.settings.email.description'),
            'fields' => [
                SettingsFields::string(
                    EnvelopeSender::SETTING_DOMAIN,
                    $this->translator->translate('vidyalms.settings.email.envelope_domain'),
                    null,
                    $this->translator->translate('vidyalms.settings.email.envelope_domain.help'),
                ),
                SettingsFields::string(
                    EnvelopeSender::SETTING_PREFIX,
                    $this->translator->translate('vidyalms.settings.email.envelope_prefix'),
                    EnvelopeSender::DEFAULT_PREFIX,
                    $this->translator->translate('vidyalms.settings.email.envelope_prefix.help'),
                ),
                SettingsFields::integer(
                    self::SETTING_SOFT_BOUNCE_THRESHOLD,
                    $this->translator->translate('vidyalms.settings.email.soft_bounce_threshold'),
                    self::DEFAULT_SOFT_BOUNCE_THRESHOLD,
                    $this->translator->translate('vidyalms.settings.email.soft_bounce_threshold.help'),
                    1,
                ),
                SettingsFields::string(
                    'feedback_sns_topic_arn',
                    $this->translator->translate('vidyalms.settings.email.sns_topic'),
                    null,
                    $this->translator->translate('vidyalms.settings.email.sns_topic.help'),
                ),
            ],
        ];
    }

    public function send(Notification $notification, SendContext $context): SendResult
    {
        $user = $this->users->findById($notification->recipientUserId);
        $protected = $context->protectedRecipientEmail !== null;
        $recipient = $context->protectedRecipientEmail ?? $user?->email;

        if ($recipient === null || filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return SendResult::failedPermanent('The recipient no longer exists.');
        }

        $message = $context->message;
        // Protected messages retain queue attempt metadata, not a second
        // plaintext recipient or secret-bearing mail log.
        $logId = $protected ? null : $this->emailLog->insertQueued(
            $notification->recipientUserId,
            $notification->templateKey,
            $recipient,
            $notification->eventReference,
            $context->attempt,
        );

        try {
            $this->mailer->send(new MailMessage(
                $notification->recipientUserId,
                $recipient,
                $notification->templateKey,
                $protected ? [] : array_replace($notification->parameters, ['recipient_name' => $user->name ?? '']),
                $notification->eventReference,
                $message->subject,
                $message->bodyText,
                $message->bodyHtml,
                $message->headers,
                $message->envelopeSender,
                $message->locale,
            ));
        } catch (\Throwable $failure) {
            if ($logId !== null) {
                $this->emailLog->markFailed($logId, $failure->getMessage());
            }

            if ($protected) {
                // A mailer's diagnostic can echo the address/body. Preserve
                // its classification without persisting that diagnostic.
                $result = $this->classify($failure->getMessage());
                return $result->outcome === \Shondalai\VidyaLMS\Core\Registry\Notification\SendOutcome::FailedTransient
                    ? SendResult::failedTransient('Protected message transport temporarily unavailable.')
                    : SendResult::failedPermanent('Protected message delivery failed.');
            }
            return $this->classify($failure->getMessage());
        }

        if ($logId !== null) {
            $this->emailLog->markSent($logId);
        }

        return SendResult::sent($context->envelopeTag());
    }

    /**
     * A permanent failure is never retried and a poisoned row is stepped over
     * rather than retried at the head of the queue, so the classification has
     * to be made by whatever knows the transport, which is this channel.
     */
    private function classify(string $error): SendResult
    {
        $normalised = strtolower($error);

        foreach (self::TRANSIENT_MARKERS as $marker) {
            if (str_contains($normalised, $marker)) {
                return SendResult::failedTransient($error);
            }
        }

        return SendResult::failedPermanent($error);
    }
}
