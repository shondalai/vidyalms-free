<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Application\Commerce\ProviderSecrets;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationDigest;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueueState;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationConsentRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationPreferenceRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationQueueRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationSuppressionRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlProviderSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Registry\Context\FeedbackContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Feedback\DeliveryFeedback;
use Shondalai\VidyaLMS\Core\Registry\Notification\Feedback\DeliveryFeedbackMapperRegistry;

/**
 * The call site the free abuse-report mapper would otherwise not have.
 *
 * The mail port is send-only, there is no inbound mail port, and a mapper
 * nothing can hand a payload to is an empty seam. A report forwarded from a
 * feedback mailbox by any means the site already has is therefore a working
 * free path, an ESP webhook is the same route with a different mapper, and
 * mailbox polling stays out of the core where it belongs.
 *
 * It is authenticated by a shared secret held in provider settings for the
 * channel and never by a session, because the sender is a machine on the far
 * side of an ESP. A request with no valid secret is refused without saying
 * whether the channel exists.
 *
 * A body no registered mapper claims is logged unparsed and writes no
 * suppression, which is the published miss of that registry rather than an
 * error.
 */
final class RecordDeliveryFeedback
{
    public const REGISTRY_KEY = 'DeliveryFeedbackMapper';
    public const SETTING_SECRET = 'feedback_secret';
    public const SETTING_SOFT_BOUNCE_THRESHOLD = 'soft_bounce_threshold';

    public function __construct(
        private readonly DeliveryFeedbackMapperRegistry $mappers,
        private readonly SqlNotificationSuppressionRepository $suppressions,
        private readonly SqlNotificationQueueRepository $queue,
        private readonly SqlProviderSettingsRepository $providerSettings,
        /**
         * The one place a stored `provider_settings.secret_ref` becomes a
         * value. The feedback secret used to be read straight out of the
         * params bag, which stored the shared secret in cleartext in a table
         * that is readable on every shared-hosting install, beside a column
         * that refuses the same value by design.
         */
        private readonly ProviderSecrets $providerSecrets,
        private readonly ClockPort $clock,
        private readonly LoggerInterface $logger,
        /**
         * The two tables an ESP-reported unsubscribe writes. Optional so a
         * harness that only tests bounce handling still builds this class;
         * absent, the report is logged rather than honoured.
         */
        private readonly ?SqlNotificationPreferenceRepository $preferences = null,
        private readonly ?SqlNotificationConsentRepository $consents = null,
        private readonly ?\Shondalai\VidyaLMS\Core\Registry\Notification\SensitiveNotificationDelivery $sensitiveDelivery = null,
    ) {
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    public function execute(string $channelKey, string $body, string $contentType, string $secret, array $headers = []): array
    {
        $this->authorize($channelKey, $secret);
        // The params bag still carries the ordinary settings, the soft-bounce
        // threshold among them, and no longer carries the secret.
        $settings = $this->providerSettings->resolve('Notification', $channelKey);
        $context = FeedbackContext::create($channelKey, $this->clock->now(), $headers);
        $mapper = $this->mappers->claimant($body, $contentType, $context);

        if ($mapper === null) {
            $this->logger->info('VidyaLMS delivery feedback was not claimed by any mapper.', [
                'channel' => $channelKey,
                'content_type' => $contentType,
                'bytes' => \strlen($body),
            ]);

            return ['mapped' => false, 'mapper_key' => null, 'type' => null, 'correlated_queue_id' => null, 'suppressed' => false];
        }

        if ($mapper instanceof \Shondalai\VidyaLMS\Core\Registry\Notification\Feedback\AuthenticatedFeedbackMapper) {
            $mapper->authenticate($body, $context, $settings);
        }
        $reports = $mapper->map($body, $contentType, $context);

        if ($reports === []) {
            return [
                'mapped' => true,
                'mapper_key' => $mapper->key(),
                'type' => null,
                'correlated_queue_id' => null,
                'suppressed' => false,
            ];
        }

        $first = $reports[0];
        $suppressed = false;
        $queueId = null;

        $honoured = 0;

        foreach ($reports as $report) {
            $correlated = $this->correlate($channelKey, $report);
            if ($correlated === null) {
                // A mailbox forward authenticates the forwarder, not the author
                // of the quoted report. Only an actual delivery may change consent.
                continue;
            }
            $queued = $this->queue->find($correlated);
            if ($queued !== null && $this->sensitiveDelivery?->handles($queued)) {
                $this->sensitiveDelivery->feedback($queued, $report);
                // A forwarded diagnostic can quote the original secret-bearing body.
                $report = new DeliveryFeedback(
                    $report->type,
                    $report->recipient,
                    $report->providerMessageId,
                    $report->occurredAt,
                    'Protected notification delivery feedback.'
                );
            }
            $queueId ??= $correlated;
            $suppressed = $this->apply($channelKey, $report, $settings) || $suppressed;
            $honoured += $this->honourUnsubscribe($channelKey, $report, $correlated) ? 1 : 0;
        }

        return [
            'mapped' => true,
            'mapper_key' => $mapper->key(),
            'type' => $first->type,
            'correlated_queue_id' => $queueId,
            'suppressed' => $suppressed,
            'unsubscribed' => $honoured,
        ];
    }

    /** Authorization preflight for native host permission callbacks; it has no delivery side effects. */
    public function authorize(string $channelKey, string $secret): void
    {
        // Resolve through the host secret store. Unknown and disabled channels
        // answer the same refusal as an incorrect secret.
        $expected = $this->providerSecrets->for('Notification', $channelKey);
        if ($expected === null || $secret === '' || !hash_equals($expected, $secret)) {
            throw new NotAuthorizedException('Authentication is required.');
        }
    }

    /**
     * An unsubscribe reported by the ESP is the same act as one pressed on
     * our own page, and is written the same way.
     *
     * It was parsed, correlated and then dropped: the type is a declared
     * member of the vocabulary and four paid mappers produce it, so a learner
     * who pressed unsubscribe in their mail client on an ESP-sent stream kept
     * receiving the category. The correlated queue row already carries the
     * recipient and the category, so everything needed to honour it was in
     * hand.
     *
     * A preference row and a consent row, and explicitly **no** suppression
     * row: a category-scoped click may not produce an address-scoped block,
     * because suppression carries no class exemption and the next certificate
     * mail would be silently dropped.
     */
    private function honourUnsubscribe(string $channelKey, DeliveryFeedback $report, ?int $queueId): bool
    {
        if ($report->type !== DeliveryFeedback::TYPE_UNSUBSCRIBE) {
            return false;
        }

        $row = $queueId === null ? null : $this->queue->find($queueId);

        if ($row === null || $row->recipientUserId === null) {
            $this->logger->info('VidyaLMS delivery feedback reported an unsubscribe with no correlated queue row.', [
                'channel' => $channelKey,
                'provider_message_id' => $report->providerMessageId,
                'reason' => 'uncorrelated_unsubscribe',
            ]);

            return false;
        }

        $this->preferences?->setPreference(
            $row->recipientUserId,
            $row->categoryKey,
            $row->channelKey ?? $channelKey,
            SqlNotificationPreferenceRepository::FREQUENCY_NEVER,
        );

        $this->consents?->append(
            $row->recipientUserId,
            $row->categoryKey,
            SqlNotificationConsentRepository::STATE_WITHDRAWN,
            SqlNotificationConsentRepository::BASIS_CONSENT,
            SqlNotificationConsentRepository::SOURCE_DELIVERY_FEEDBACK,
            ['channel_key' => $row->channelKey ?? $channelKey, 'queue_id' => $queueId],
        );

        return true;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function apply(string $channelKey, DeliveryFeedback $report, array $settings): bool
    {
        if ($report->recipient === null || $report->recipient === '') {
            return false;
        }

        $digest = NotificationDigest::forAddress($report->recipient);

        if ($report->suppressesImmediately()) {
            $reason = $report->type === DeliveryFeedback::TYPE_COMPLAINT
                ? SqlNotificationSuppressionRepository::REASON_COMPLAINT
                : SqlNotificationSuppressionRepository::REASON_HARD_BOUNCE;

            $this->suppressions->suppress($channelKey, $digest, $reason, $report->diagnostic);

            return true;
        }

        if ($report->type !== DeliveryFeedback::TYPE_SOFT_BOUNCE) {
            return false;
        }

        $threshold = $settings[self::SETTING_SOFT_BOUNCE_THRESHOLD] ?? null;

        return $this->suppressions->recordSoftBounce(
            $channelKey,
            $digest,
            \is_int($threshold) ? $threshold : SqlNotificationSuppressionRepository::DEFAULT_SOFT_BOUNCE_THRESHOLD,
            $report->diagnostic,
        );
    }

    private function correlate(string $channelKey, DeliveryFeedback $report): ?int
    {
        if ($report->providerMessageId === null || $report->providerMessageId === '') {
            return null;
        }

        $row = $this->queue->findByProviderMessageId($report->providerMessageId);
        if ($row === null || $row->state !== QueueState::Sent || $row->channelKey !== $channelKey
            || $row->recipientAddressDigest === null || $report->recipient === null
            || !hash_equals($row->recipientAddressDigest, NotificationDigest::forAddress($report->recipient))) {
            return null;
        }
        return $row->id;
    }
}
