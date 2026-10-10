<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgTenant;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationDigest;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueuedNotification;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueueState;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationPreferenceRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationQueueRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlProviderSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Authorization\AuthorizationPort;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Users\RecipientProfilePort;
use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Context\NotificationDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\ActsAtCreation;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\NotificationPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\SuppressionReason;

/**
 * Turns one intent row into per-recipient rows.
 *
 * It runs outside the business transaction on purpose. A rule over a five
 * thousand member cohort must not run five thousand policy evaluations inside
 * the transaction that started the run, and the audience resolver returns a
 * generator consumed in bounded batches so no resolver may materialise a
 * roster in memory.
 *
 * A digest preference writes a pending digest item rather than a queue row,
 * which is what a daily or weekly frequency means, and each item carries its
 * own dedupe digest so the digest path inherits the queue's exactly-once
 * property rather than opting out of it.
 */
final class NotificationFanout
{
    /** How many intent rows one run expands, so a run is bounded. */
    public const DEFAULT_LIMIT = 50;

    public function __construct(
        private readonly SqlNotificationQueueRepository $queue,
        private readonly SqlNotificationRuleRepository $rules,
        private readonly AudienceResolverRegistry $audiences,
        private readonly SqlNotificationPreferenceRepository $preferences,
        private readonly DatabasePort $database,
        private readonly AuthorizationPort $authorization,
        private readonly ClockPort $clock,
        private readonly LoggerInterface $logger,
        private readonly string $siteTimezone = 'UTC',
        private readonly ?RecipientProfilePort $profiles = null,
        /**
         * The policies that act at creation rather than at send. Optional so
         * a harness that only exercises fan-out still builds this class.
         */
        private readonly ?NotificationPolicyRegistry $policies = null,
        private readonly ?SqlProviderSettingsRepository $providerSettings = null,
        /**
         * The second hop's wake-up. Optional on the same terms as the rest:
         * absent, a recipient row waits for the periodic drain, which is what
         * every row did before this existed.
         */
        private readonly ?NotificationJobs $jobs = null,
    ) {
    }

    public function run(int $limit = self::DEFAULT_LIMIT, int $binIndex = 0, int $binCount = 1): int
    {
        $expanded = 0;
        $claimToken = bin2hex(random_bytes(16));

        $this->queue->reclaimExpiredLeases($limit);

        foreach ($this->queue->claimIntents($claimToken, $limit, $binIndex, $binCount) as $intent) {
            $expanded += $this->expand($intent);
        }

        return $expanded;
    }

    private function expand(QueuedNotification $intent): int
    {
        $intentId = $intent->id;

        if ($intentId === null) {
            return 0;
        }

        $rule = $intent->ruleId === null ? null : $this->rules->find($intent->ruleId);

        if ($rule === null) {
            $this->queue->markSuppressed($intentId, SuppressionReason::TEMPLATE_MISSING);

            return 0;
        }

        if (!$this->audiences->has($rule->audienceKey)) {
            $this->logger->notice('VidyaLMS notification audience is missing.', [
                'rule' => $rule->ruleKey,
                'audience' => $rule->audienceKey,
            ]);
            $this->queue->markSuppressed($intentId, AudienceResolverRegistry::MISS_REASON);
            $this->rules->incrementCounter($rule->id ?? 0, 'suppressed_count');

            return 0;
        }

        // Still no OrgScope, and still for the structural reason: a scope is
        // minted only from a witness carrying a positive actor, and a queued
        // intent records no actor at all. Inventing one would put a hole in
        // the access spine to save a parameter.
        //
        // What an occurrence CAN carry is the organisation the use case that
        // raised it already resolved, stamped on the payload, and that is
        // what an organisation audience needs: which tenant, not whose
        // authority. An intent with no stamp derives no tenant and every
        // organisation audience answers nobody, which is the behaviour every
        // shipped fan-out had before this line existed.
        $context = AudienceContext::create(
            $this->database,
            $this->authorization,
            $intent->subjectType,
            $intent->subjectId,
            $this->courseIdOf($intent),
            $this->learnerIdOf($intent),
            $rule->audienceConfig,
            stampedTenant: OrgTenant::fromStampedOccurrence($intent->parameters),
        );
        $resolver = $this->audiences->get($rule->audienceKey);

        // The refusal four docblocks published and nothing implemented. The
        // rule lives on the registry so any scoped run asks the same question
        // rather than each caller rewriting it.
        $refusal = AudienceResolverRegistry::refusalFor($resolver, $context);

        if ($refusal !== null) {
            $this->logger->notice('VidyaLMS notification audience does not honour an organisation scope.', [
                'rule' => $rule->ruleKey,
                'audience' => $rule->audienceKey,
            ]);
            $this->queue->markSuppressed($intentId, $refusal);
            $this->rules->incrementCounter($rule->id ?? 0, 'suppressed_count');

            return 0;
        }

        $written = 0;
        $seen = [];

        foreach ($resolver->resolve($context) as $recipientUserId) {
            if ($recipientUserId < 1 || isset($seen[$recipientUserId])) {
                continue;
            }

            $seen[$recipientUserId] = true;
            $written += $this->writeFor($intent, $rule->id, $rule->channels, $recipientUserId);
        }

        if ($written === 0 && $seen === []) {
            // A registered audience that needed a tenant and was handed none
            // is not a missing audience, and reporting it as one sends an
            // administrator looking for a package that is already installed.
            $this->queue->markSuppressed($intentId, AudienceResolverRegistry::emptyReasonFor($resolver, $context));
            $this->rules->incrementCounter($rule->id ?? 0, 'suppressed_count');

            return 0;
        }

        // The intent row is terminal once its recipients exist: it is not a
        // delivery, it must never be selected by the drain, and it may not
        // borrow "sent" or every occurrence inflates the delivered count on
        // the queue screen by one.
        $this->queue->update($intentId, [
            'state' => QueueState::FannedOut->value,
            'claim_token' => null,
            'lease_expires_at' => null,
        ]);

        return $written;
    }

    /**
     * @param list<string> $channels
     */
    private function writeFor(QueuedNotification $intent, ?int $ruleId, array $channels, int $recipientUserId): int
    {
        $written = 0;
        $timezone = $this->profiles?->timezoneFor($recipientUserId) ?? $this->siteTimezone;

        foreach ($channels as $channelKey) {
            $resolution = PreferenceResolution::resolve(
                $this->preferences->policies()[$intent->categoryKey . '|' . $channelKey]['state'] ?? null,
                $this->preferences->frequencyFor($recipientUserId, $intent->categoryKey, $channelKey),
                $intent->class,
            );

            if ($resolution->isDigest() && $intent->class !== NotificationClass::Transactional) {
                $this->writeDigestItem($intent, $ruleId, $recipientUserId, $channelKey, $resolution->frequency);
                ++$written;
                continue;
            }

            // One recipient's collision is one recipient skipped, not the
            // roster abandoned: the enqueue is idempotent by the dedupe
            // digest and answers null for a duplicate, but a driver-level
            // failure used to escape the whole loop and leave the intent row
            // mid-expansion with some recipients written and some not.
            $dedupeKey = NotificationDigest::forDelivery(
                NotificationDigest::notificationRuleRef($ruleId),
                $intent->triggerKey,
                $intent->eventReference,
                $intent->templateKey,
                $channelKey,
                $recipientUserId,
                $intent->occurrenceKey,
            );

            try {
                $id = $this->queue->enqueue(new QueuedNotification(
                    null,
                    $ruleId,
                    $intent->triggerKey,
                    $intent->categoryKey,
                    $intent->class,
                    $intent->templateKey,
                    $intent->eventReference,
                    $intent->occurrenceKey,
                    $dedupeKey,
                    $intent->runAt,
                    QueueState::Pending,
                    $recipientUserId,
                    $channelKey,
                    $intent->subjectType,
                    $intent->subjectId,
                    $intent->parameters,
                    $timezone,
                ));
            } catch (\Throwable $failure) {
                $this->logger->error('VidyaLMS notification fan-out could not write one recipient row.', [
                    'intent' => $intent->id,
                    'recipient' => $recipientUserId,
                    'channel' => $channelKey,
                    'exception' => $failure,
                ]);

                continue;
            }

            if ($id !== null) {
                ++$written;
                $this->actAtCreation($intent, $recipientUserId, $channelKey, $id, $timezone);

                // After the creation policies, because one of them cancels a
                // pending row: a job queued before the cancellation would
                // wake a drain that can only decline, and the drain is the
                // one that decides anyway.
                $this->jobs?->queueSend($id, $dedupeKey, $intent->runAt);
            }
        }

        return $written;
    }

    /**
     * The policies that act the moment a row exists rather than at send.
     *
     * The duplicate window is the whole reason this exists: cancelling a
     * pending row is its entire purpose, and from the send-time fold it
     * cancels in the wrong direction, because the drain claims in run-at
     * order so the oldest row does the cancelling.
     */
    private function actAtCreation(
        QueuedNotification $intent,
        int $recipientUserId,
        string $channelKey,
        int $queueId,
        string $timezone,
    ): void {
        foreach ($this->policies?->all() ?? [] as $policy) {
            if (!$policy instanceof ActsAtCreation) {
                continue;
            }

            $notification = new Notification(
                $recipientUserId,
                $intent->templateKey,
                $intent->parameters,
                $intent->eventReference,
                $intent->class,
                $intent->categoryKey,
                $intent->occurrenceKey,
            );

            $policy->onEnqueued($notification, NotificationDecisionContext::create(
                $this->clock->now(),
                $this->database,
                [$channelKey],
                $this->providerSettings?->resolve('NotificationPolicy', $policy->key()) ?? [],
                $timezone,
                null,
                $intent->ruleId,
                $intent->subjectType,
                $intent->subjectId,
                $queueId,
                $intent->triggerKey,
            ));
        }
    }

    private function writeDigestItem(
        QueuedNotification $intent,
        ?int $ruleId,
        int $recipientUserId,
        string $channelKey,
        string $frequency,
    ): void {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $dedupeKey = NotificationDigest::forDigestItem(
            NotificationDigest::notificationRuleRef($ruleId),
            $intent->triggerKey,
            $intent->eventReference,
            $intent->categoryKey,
            $channelKey,
            $recipientUserId,
            $intent->occurrenceKey,
            $frequency,
        );

        $existing = $this->database->selectOne(
            \Shondalai\VidyaLMS\Core\Port\Database\Query::from('notification_digest_items')
                ->select(['id'])
                ->where('dedupe_key', '=', $dedupeKey),
        );

        if ($existing !== null) {
            return;
        }

        $this->database->insert('notification_digest_items', [
            'recipient_user_id' => $recipientUserId,
            'channel_key' => $channelKey,
            'category_key' => $intent->categoryKey,
            'frequency' => $frequency,
            'trigger_key' => $intent->triggerKey,
            'subject_type' => $intent->subjectType,
            'subject_id' => $intent->subjectId,
            'parameters' => $intent->parameters === []
                ? null
                : json_encode($intent->parameters, \JSON_THROW_ON_ERROR),
            'dedupe_key' => $dedupeKey,
            'occurred_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function courseIdOf(QueuedNotification $intent): ?int
    {
        $courseId = $intent->parameters['course_id'] ?? null;

        if (\is_numeric($courseId)) {
            return (int) $courseId;
        }

        return $intent->subjectType === 'course' ? $intent->subjectId : null;
    }

    private function learnerIdOf(QueuedNotification $intent): ?int
    {
        $userId = $intent->parameters['recipient_user_id'] ?? ($intent->parameters['user_id'] ?? null);

        return \is_numeric($userId) ? (int) $userId : null;
    }
}
