<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Notification\EnvelopeSender;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationDigest;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueuedNotification;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueueState;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationQueueRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlProviderSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;
use Shondalai\VidyaLMS\Core\Port\Mail\MailHeaders;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;
use Shondalai\VidyaLMS\Core\Port\Users\RecipientProfilePort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Registry\Context\NotificationDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\SendContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationChannelRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\NotificationPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\SuppressionReason;
use Shondalai\VidyaLMS\Core\Registry\Notification\RenderedMessage;
use Shondalai\VidyaLMS\Core\Registry\Notification\SendOutcome;
use Shondalai\VidyaLMS\Core\Registry\Notification\SendResult;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsListHeaders;

/**
 * The drain.
 *
 * A drain claims before it sends. Both hosts are documented multi-firers, so
 * two runners over one pending row is the normal case rather than the exotic
 * one, and a dedupe digest deduplicates rows and not sends.
 *
 * Audience, conditions and the frequency cap are re-resolved here and never
 * snapshotted at enqueue, because any delay measured in days can outlive the
 * reason for the message: a seven-day nudge conditioned on "has not
 * completed" must not mail a learner who finished on day two.
 *
 * The handler never throws to signal a delivery failure. The scheduled task
 * contract says the runner owns retry and backoff, so leaving this to throw
 * would put two backoff regimes on one unit of work, composing differently
 * under each host's runner, with the runner's retries defeating the attempt
 * cap and the jitter curve never actually running. Retry is owned by the
 * queue and by nothing else: full jitter, a hard attempt cap, and a permanent
 * failure never retried.
 */
final class NotificationSender
{
    public const DEFAULT_LIMIT = 25;

    /** The attempt cap. A poisoned row is stepped over, never retried forever. */
    public const MAX_ATTEMPTS = 5;

    /** The base of the backoff curve, in seconds. */
    public const BACKOFF_BASE_SECONDS = 30;

    /** The ceiling of the backoff curve, in seconds. */
    public const BACKOFF_CAP_SECONDS = 3600;

    public const REGISTRY_POLICY = 'NotificationPolicy';
    public const REGISTRY_CHANNEL = 'Notification';

    public function __construct(
        private readonly SqlNotificationQueueRepository $queue,
        private readonly SqlNotificationRuleRepository $rules,
        private readonly NotificationPolicyRegistry $policies,
        private readonly NotificationChannelRegistry $channels,
        private readonly NotificationRenderer $renderer,
        private readonly SqlProviderSettingsRepository $providerSettings,
        private readonly UsersPort $users,
        private readonly ClockPort $clock,
        private readonly RandomPort $random,
        private readonly LoggerInterface $logger,
        private readonly LocalePort $locale,
        private readonly DatabasePort $database,
        private readonly ?RecipientProfilePort $profiles = null,
        private readonly ?ConditionsStillHold $conditions = null,
        private readonly ?UnsubscribeTokens $unsubscribe = null,
        private readonly string $unsubscribeBaseUrl = '',
        /**
         * The mailto: fallback RFC 2369 lists after the https URI, for a site
         * whose mail is answered by a person rather than by a route.
         */
        private readonly string $unsubscribeMailto = '',
        /**
         * The host's own default envelope domain, which the adapter derives
         * from the site's from address and never from the request host. It is
         * what a site that configured nothing sends with; the channel's
         * provider settings row overrides it.
         */
        private readonly string $envelopeDomain = '',
        private readonly ?\Shondalai\VidyaLMS\Core\Registry\Notification\SensitiveNotificationDelivery $sensitive = null,
    ) {
    }

    public function drain(int $limit = self::DEFAULT_LIMIT): int
    {
        $this->sensitive?->prune($limit);
        $this->queue->releaseDue();

        // An expired lease is returned to pending on a compare-and-swap over
        // the dead runner's token before anything is selected, so there is one
        // claim shape and the recovery path cannot be unconditional. It used
        // to claim "sending to sending", where the predicate is satisfied by
        // the value the statement writes, so every runner that saw the row
        // claimed it and every one of them sent.
        $this->queue->reclaimExpiredLeases($limit);
        $sent = 0;

        foreach ($this->queue->drainable($limit) as $row) {
            if ($this->deliverSafely($row)) {
                ++$sent;
            }
        }

        return $sent;
    }

    /**
     * Delivers one authoring test without creating or mutating a queue row.
     *
     * The application service owns the self-only recipient and immutable
     * trigger class. This seam owns the same last-mile policy fold and legal
     * headers as the queue drain. The message factory runs only after that
     * fold and receives the one real unsubscribe URL issued for this actor,
     * so a synthetic preview URL can never reach a transport.
     *
     * A channel outcome is returned, including an uncertain failure. It is
     * never converted to a throw that an HTTP idempotency wrapper could
     * release and automatically retry. A third-party channel that violates
     * the no-throw contract is likewise converted to a terminal test result.
     *
     * @param callable(array<string, string>, ?string): RenderedMessage $messageFactory
     */
    public function deliverTest(
        Notification $notification,
        string $channelKey,
        string $recipientTimezone,
        callable $messageFactory,
    ): SendResult {
        if (!$this->channels->has($channelKey)) {
            throw ValidationException::forField('channel_key', 'Unknown notification channel.');
        }

        $channel = $this->channels->get($channelKey);

        if ($notification->class->requiresUnsubscribeHeaders() && !$channel instanceof SupportsListHeaders) {
            throw ConflictException::with('The test delivery was suppressed.', [
                'outcome' => 'suppressed',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'reason' => SuppressionReason::CHANNEL_MISSING,
            ]);
        }

        $user = $this->users->findById($notification->recipientUserId);

        if ($user === null || trim($user->email) === '') {
            throw ConflictException::with('The acting account has no primary email address.', [
                'outcome' => 'failed',
                'reason' => 'primary_email_missing',
            ]);
        }

        $recipientTimezone = $this->profiles?->timezoneFor($notification->recipientUserId)
            ?? ($recipientTimezone === '' ? 'UTC' : $recipientTimezone);

        $decision = $this->foldFor(
            $notification,
            $channelKey,
            $recipientTimezone,
            NotificationDigest::forAddress($user->email),
        );

        if (isset($decision->denied[$channelKey])) {
            throw ConflictException::with('The test delivery was suppressed by notification policy.', [
                'outcome' => 'suppressed',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'reason' => $decision->denied[$channelKey],
            ]);
        }

        if (isset($decision->deferred[$channelKey])) {
            throw ConflictException::with('The test delivery is deferred by quiet hours.', [
                'outcome' => 'deferred',
                'reason' => 'quiet_hours',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'retry_at' => $decision->deferred[$channelKey]->format(\DATE_ATOM),
            ]);
        }

        $unsubscribeUrl = $notification->class->requiresUnsubscribeHeaders()
            ? $this->unsubscribeUrlFor($notification)
            : null;

        // A structured non-transactional message contains a visible
        // unsubscribe action. mailto alone can make a header lawful, but it
        // cannot replace that HTTPS action inside the body.
        if ($notification->class->requiresUnsubscribeHeaders() && $unsubscribeUrl === null) {
            throw ConflictException::with('The test delivery was suppressed.', [
                'outcome' => 'suppressed',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'reason' => SuppressionReason::UNSUBSCRIBE_UNAVAILABLE,
            ]);
        }

        if ($unsubscribeUrl !== null) {
            $notification = $notification->withParameters(
                array_replace($notification->parameters, ['unsubscribe.url' => $unsubscribeUrl]),
            );
        }

        $headers = MailHeaders::build(
            $notification->class->value,
            $unsubscribeUrl,
            $this->unsubscribeMailto === '' ? null : $this->unsubscribeMailto,
            $notification->eventReference,
            $channelKey,
        );
        $message = $messageFactory($headers, $unsubscribeUrl);

        try {
            return $channel->send($notification, SendContext::create(
                $message,
                null,
                1,
                $recipientTimezone,
                $this->clock->now(),
            ));
        } catch (\Throwable $failure) {
            $this->logger->error('VidyaLMS notification test channel threw.', [
                'channel' => $channelKey,
                'exception' => $failure,
            ]);

            return SendResult::failedPermanent('The transport result is uncertain; this test will not be retried.');
        }
    }

    /**
     * One row, with the batch protected from it.
     *
     * A channel that throws used to propagate out of the send task, so every
     * later row in the batch was abandoned, record() was never reached, the
     * attempt cap never fired, and the poisoned row came back on the next
     * drain to throw again. The queue owns retry and nothing else does, so a
     * throw is classified as a transient failure and given to the backoff and
     * the cap like any other. The shipped free channel catches for itself;
     * the design invites third parties to register channels, and this is the
     * same guard the composer applies to condition providers rather than
     * trusting them.
     */
    private function deliverSafely(QueuedNotification $row): bool
    {
        try {
            return $this->deliver($row);
        } catch (\Throwable $failure) {
            $protected = $this->sensitive?->handles($row) ?? false;
            $this->logger->error('VidyaLMS notification channel threw.', [
                'queue_id' => $row->id,
                'channel' => $row->channelKey,
                'exception' => $protected ? 'Protected message processing failed.' : $failure,
            ]);

            $claimed = $row->id === null ? null : $this->queue->find($row->id);

            if ($claimed !== null && $claimed->state === QueueState::Sending) {
                $this->record(
                    $claimed,
                    SendOutcome::FailedTransient,
                    null,
                    $protected ? 'Protected message processing failed.' : $failure->getMessage(),
                    null
                );
            }

            return false;
        }
    }

    private function deliver(QueuedNotification $row): bool
    {
        $id = $row->id;
        $recipientUserId = $row->recipientUserId;
        $channelKey = $row->channelKey;

        if ($id === null || $recipientUserId === null || $channelKey === null) {
            return false;
        }

        $fromState = $row->state->value;

        if (!$this->queue->claim($id, $this->random->uuid(), $fromState)) {
            return false;
        }

        $claimed = $this->queue->find($id);

        if ($claimed === null) {
            return false;
        }

        if (!$this->channels->has($channelKey)) {
            $this->suppress($claimed, SuppressionReason::CHANNEL_MISSING);

            return false;
        }

        $notification = new Notification(
            $recipientUserId,
            $claimed->templateKey,
            $claimed->parameters,
            $claimed->eventReference,
            $claimed->class,
            $claimed->categoryKey,
            $claimed->occurrenceKey,
        );
        $protectedRecipient = null;
        if ($this->sensitive?->handles($claimed)) {
            $prepared = $this->sensitive->prepare($claimed);
            if ($prepared === null) {
                $this->suppress($claimed, 'protected_message_expired_or_cancelled');
                return false;
            }
            $notification = $prepared->notification;
            $recipientUserId = $notification->recipientUserId;
            $protectedRecipient = $prepared->recipientEmail;
        }

        if ($this->conditionsNoLongerHold($claimed)) {
            $this->suppress($claimed, SuppressionReason::CONDITIONS_NO_LONGER_HOLD);

            return false;
        }

        // Entitlement and enrolment are re-resolved at send and never
        // snapshotted at enqueue: any delay measured in days can outlive the
        // reason for the message, and the nudge that arrives seven days after
        // an unenrolment is the worked example the rule exists for. The
        // transactional class is exempt, so a certificate mail still reaches
        // a learner whose access has since lapsed.
        if (!$this->accessStillHolds($claimed, $recipientUserId)) {
            $this->suppress($claimed, SuppressionReason::ENROLMENT_NO_LONGER_ACTIVE);

            return false;
        }

        // A non-transactional message with no lawful unsubscribe mechanism is
        // refused rather than sent headerless, which is the same direction
        // the missing-list-header channel refusal already takes.
        if (!$this->canCarryClass($notification)) {
            $this->suppress($claimed, SuppressionReason::UNSUBSCRIBE_UNAVAILABLE);

            return false;
        }

        $user = $this->users->findById($recipientUserId);
        $address = $protectedRecipient ?? $user?->email;
        $addressDigest = $address === null ? null : NotificationDigest::forAddress($address);

        $decision = $this->fold($notification, $claimed, $channelKey, $addressDigest);

        if (isset($decision->denied[$channelKey])) {
            $this->suppress($claimed, $decision->denied[$channelKey]);

            return false;
        }

        if (isset($decision->deferred[$channelKey])) {
            $this->queue->markDeferred($id, $decision->deferred[$channelKey]);

            return false;
        }

        $locale = $this->profiles?->localeFor($recipientUserId);
        $chain = array_values(array_unique(array_filter([$locale, $this->locale->current()])));

        $message = $this->renderer->render(
            $notification,
            $channelKey,
            $chain,
            $this->headersFor($notification, $channelKey, $claimed),
            $this->envelopeSenderFor($id, $channelKey),
            $this->courseIdOf($claimed),
            $claimed->recipientTimezone ?? 'UTC',
        );

        if ($message === null) {
            $this->suppress($claimed, SuppressionReason::TEMPLATE_MISSING);

            return false;
        }

        $channel = $this->channels->get($channelKey);

        if ($notification->class->requiresUnsubscribeHeaders() && !$channel instanceof SupportsListHeaders) {
            // A channel that cannot carry list headers may not carry a
            // non-transactional class, because the headers would be dropped
            // and the lawful unsubscribe mechanism with them.
            $this->suppress($claimed, SuppressionReason::CHANNEL_MISSING);

            return false;
        }

        $result = $channel->send($notification, SendContext::create(
            $message,
            $id,
            $claimed->attempts,
            $claimed->recipientTimezone ?? 'UTC',
            $this->clock->now(),
            $protectedRecipient,
        ));

        $this->queue->update($id, ['recipient_locale' => $message->locale, 'recipient_address_digest' => $addressDigest]);

        return $this->record($claimed, $result->outcome, $result->providerMessageId, $result->error, $result->retryAfter);
    }

    private function record(
        QueuedNotification $row,
        SendOutcome $outcome,
        ?string $providerMessageId,
        ?string $error,
        ?\DateTimeImmutable $retryAfter,
    ): bool {
        $id = $row->id ?? 0;

        if ($outcome === SendOutcome::Sent) {
            $this->queue->markSent($id, $providerMessageId);
            $this->sensitive?->purge($id);

            if ($row->ruleId !== null) {
                $this->rules->incrementCounter($row->ruleId, 'sent_count');
            }

            return true;
        }

        if ($outcome === SendOutcome::Deferred) {
            $this->queue->markDeferred($id, $retryAfter ?? $this->clock->now()->modify('+1 hour'));

            return false;
        }

        if ($outcome === SendOutcome::FailedPermanent) {
            $this->queue->markFailedPermanent($id, $error ?? 'Permanent delivery failure.');
            $this->sensitive?->purge($id);

            return false;
        }

        if ($row->attempts >= self::MAX_ATTEMPTS) {
            $this->queue->markFailedPermanent($id, $error ?? 'Attempt cap reached.');
            $this->sensitive?->purge($id);

            return false;
        }

        $this->queue->returnToPending($id, $retryAfter ?? $this->backoff($row->attempts), $error);

        return false;
    }

    /**
     * Full jitter, which measures as the lowest total client work across
     * contention levels, and which un-jittered exponential backoff loses to
     * by a wide margin.
     */
    private function backoff(int $attempt): \DateTimeImmutable
    {
        $ceiling = min(self::BACKOFF_CAP_SECONDS, self::BACKOFF_BASE_SECONDS * (2 ** max(0, $attempt)));
        $delay = random_int(1, max(1, (int) $ceiling));

        return $this->clock->now()->modify('+' . $delay . ' seconds');
    }

    private function fold(
        Notification $notification,
        QueuedNotification $row,
        string $channelKey,
        ?string $addressDigest,
    ): PolicyDecision {
        return $this->foldFor(
            $notification,
            $channelKey,
            $row->recipientTimezone ?? 'UTC',
            $addressDigest,
            $row,
        );
    }

    private function foldFor(
        Notification $notification,
        string $channelKey,
        string $recipientTimezone,
        ?string $addressDigest,
        ?QueuedNotification $row = null,
    ): PolicyDecision {
        // A site-wide channel switch applies to queued messages and test sends.
        // Course and rule settings cannot override an administrator's stop.
        if (!$this->providerSettings->isEnabled(self::REGISTRY_CHANNEL, $channelKey)) {
            return PolicyDecision::deny([$channelKey], SuppressionReason::CHANNEL_DISABLED);
        }

        $base = NotificationDecisionContext::create(
            $this->clock->now(),
            $this->database,
            [$channelKey],
            [],
            $recipientTimezone,
            $addressDigest,
            $row?->ruleId,
            $row?->subjectType,
            $row?->subjectId,
            $row?->id,
            $row === null ? '' : $row->triggerKey,
        );

        $decision = PolicyDecision::allow();

        foreach ($this->policies->all() as $policy) {
            $scopes = [];

            if ($row !== null && $row->ruleId !== null) {
                $scopes[SqlProviderSettingsRepository::SCOPE_RULE] = $row->ruleId;
            }

            $courseId = $row === null ? null : $this->courseIdOf($row);

            if ($courseId !== null) {
                $scopes[SqlProviderSettingsRepository::SCOPE_COURSE] = $courseId;
            }

            $settings = $this->providerSettings->resolve(self::REGISTRY_POLICY, $policy->key(), $scopes);
            $decision = $decision->merge($policy->decide($notification, $base->withSettings($settings)));
        }

        return $decision;
    }

    private function conditionsNoLongerHold(QueuedNotification $row): bool
    {
        if ($row->ruleId === null || $this->conditions === null) {
            return false;
        }

        $rule = $this->rules->find($row->ruleId);

        if ($rule === null || $rule->conditions === null || $rule->conditions->isEmpty()) {
            return false;
        }

        $recipientUserId = $row->recipientUserId ?? 0;

        if ($recipientUserId < 1) {
            return false;
        }

        return !$this->conditions->hold(
            $rule->conditions,
            AvailabilityContext::create(
                Principal::user($recipientUserId),
                AvailabilitySurface::NotificationRule,
                $this->clock->now(),
            ),
            $this->courseIdOf($row),
        );
    }

    /**
     * Whether the recipient still has the standing the message was written
     * for.
     *
     * The queue row stores intent, not a snapshot of the reason: the design
     * lists entitlement and enrolment state alongside the conditions tree,
     * preference, consent, suppression and the frequency cap in what the
     * sender re-resolves immediately before handing to a channel, and cites
     * the precedent of a digest builder dropping rows for users who no longer
     * hold an active enrolment.
     *
     * A message with no course to check against passes: an account notice is
     * not about a course and must not be dropped for lacking one.
     */
    private function accessStillHolds(QueuedNotification $row, int $recipientUserId): bool
    {
        if ($row->class === NotificationClass::Transactional) {
            return true;
        }

        $courseId = $this->courseIdOf($row);

        if ($courseId === null) {
            return true;
        }

        $enrolment = $this->database->selectOne(
            Query::from('enrolments')
                ->select(['status'])
                ->where('user_id', '=', $recipientUserId)
                ->where('course_id', '=', $courseId),
        );

        if ($enrolment === null) {
            // A recipient who is not a learner of the course is legitimate:
            // an instructor is mailed about a submission in a course they
            // teach and never enrol in.
            return $recipientUserId !== $this->learnerOf($row);
        }

        return \in_array((string) $enrolment['status'], ['active', 'completed'], true);
    }

    private function learnerOf(QueuedNotification $row): ?int
    {
        $userId = $row->parameters['user_id'] ?? null;

        return \is_numeric($userId) ? (int) $userId : null;
    }

    private function suppress(QueuedNotification $row, string $reason): void
    {
        $this->queue->markSuppressed($row->id ?? 0, $reason);
        $this->sensitive?->purge($row->id ?? 0);

        if ($row->ruleId !== null) {
            $this->rules->incrementCounter($row->ruleId, 'suppressed_count');
        }

        $this->logger->info('VidyaLMS notification suppressed.', [
            'queue_id' => $row->id,
            'reason' => $reason,
        ]);
    }

    /**
     * The header map, built by the one class that owns it.
     *
     * It used to be two headers assembled here by hand, so Auto-Submitted,
     * Precedence, Feedback-ID, the https-only guard and the mailto fallback
     * were written by a builder no production path ever called: the only
     * callers were the two smoke harnesses, which built the map themselves
     * and then asserted the mailer preserved it, proving the builder rather
     * than the sender.
     *
     * @return array<string, string>
     */
    private function headersFor(Notification $notification, string $channelKey, QueuedNotification $row): array
    {
        return MailHeaders::build(
            $notification->class->value,
            $this->unsubscribeUrlFor($notification),
            $this->unsubscribeMailto === '' ? null : $this->unsubscribeMailto,
            $row->dedupeKey,
            $channelKey,
        );
    }

    /**
     * The recipient's own one-click URL, or null when this site cannot issue
     * one.
     */
    private function unsubscribeUrlFor(Notification $notification): ?string
    {
        if (!$notification->class->requiresUnsubscribeHeaders() || $this->unsubscribe === null) {
            return null;
        }

        $base = rtrim($this->unsubscribeBaseUrl, '/');

        if ($base === '' || !str_starts_with($base, 'https://')) {
            return null;
        }

        $token = $this->unsubscribe->issue($notification->recipientUserId, $notification->categoryKey);

        return \Shondalai\VidyaLMS\Core\Domain\Shared\PublicLink::append($base, $token);
    }

    /**
     * Whether this send may lawfully go out without the RFC 8058 pair.
     *
     * A transactional message may: it carries no unsubscribe mechanism by
     * design and a site is sometimes obliged to deliver it. Every other class
     * may not, so a site with no unsubscribe base URL configured suppresses
     * with a named reason rather than sending a headerless bulk message. That
     * is the same refusal already applied to a channel with no list-header
     * capability, and it is the honest one: a silent headerless send is the
     * CAN-SPAM, PECR and CASL failure, and a suppression with a reason on the
     * counters screen is a configuration bug an administrator can see.
     */
    private function canCarryClass(Notification $notification): bool
    {
        return !$notification->class->requiresUnsubscribeHeaders()
            || $this->unsubscribeUrlFor($notification) !== null
            || str_starts_with($this->unsubscribeMailto, 'mailto:');
    }

    /**
     * The full VERP address a bounce is attributed from, or null when this
     * site cannot compose one.
     *
     * It used to be the bare tag, which is not an address: PHPMailer
     * validates Sender in preSend() and refuses the message, so every queued
     * notification on both hosts failed permanently without ever reaching a
     * transport. An address that cannot be composed is refused here instead,
     * with the reason named, and the message goes out with no envelope
     * sender: the adapter contract already says a transport that cannot set
     * one loses the attribution and nothing else, and stopping a site's mail
     * over a misconfigured bounce domain would trade a lost bounce report for
     * a lost message.
     *
     * The domain is the channel's provider settings row, then the host
     * default the adapter derived from the site's from address. The request
     * host is not a candidate at either end: a queue drained by a scheduled
     * task has no request.
     */
    private function envelopeSenderFor(int $queueId, string $channelKey): ?string
    {
        $settings = $this->providerSettings->resolve(self::REGISTRY_CHANNEL, $channelKey);

        $domain = $settings[EnvelopeSender::SETTING_DOMAIN] ?? null;
        $domain = \is_string($domain) && trim($domain) !== '' ? $domain : $this->envelopeDomain;

        $prefix = $settings[EnvelopeSender::SETTING_PREFIX] ?? null;
        $prefix = \is_string($prefix) && trim($prefix) !== '' ? $prefix : EnvelopeSender::DEFAULT_PREFIX;

        $sender = EnvelopeSender::build(EnvelopeSender::tagFor($queueId), $domain, $prefix);

        if ($sender->address === null) {
            $this->logger->warning('VidyaLMS sent a notification with no envelope sender.', [
                'queue_id' => $queueId,
                'channel' => $channelKey,
                'reason' => $sender->reason,
                'setting' => EnvelopeSender::SETTING_DOMAIN,
            ]);

            return null;
        }

        return $sender->address;
    }

    private function courseIdOf(QueuedNotification $row): ?int
    {
        $courseId = $row->parameters['course_id'] ?? null;

        if (\is_numeric($courseId)) {
            return (int) $courseId;
        }

        return $row->subjectType === 'course' ? $row->subjectId : null;
    }
}
