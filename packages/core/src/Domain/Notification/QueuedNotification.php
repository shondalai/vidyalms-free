<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Notification;

use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;

/**
 * One queue row, as the domain sees it.
 *
 * A row stores intent, never a snapshot of the decision: audience,
 * conditions and the frequency cap are all re-resolved at send, because any
 * delay measured in days can outlive the reason for the message.
 */
final class QueuedNotification
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $ruleId,
        public readonly string $triggerKey,
        public readonly string $categoryKey,
        public readonly NotificationClass $class,
        public readonly string $templateKey,
        public readonly string $eventReference,
        public readonly string $occurrenceKey,
        public readonly string $dedupeKey,
        public readonly \DateTimeImmutable $runAt,
        public readonly QueueState $state = QueueState::Pending,
        public readonly ?int $recipientUserId = null,
        public readonly ?string $channelKey = null,
        public readonly ?string $subjectType = null,
        public readonly ?int $subjectId = null,
        public readonly array $parameters = [],
        public readonly ?string $recipientTimezone = null,
        public readonly ?string $recipientLocale = null,
        public readonly int $attempts = 0,
        public readonly ?\DateTimeImmutable $nextAttemptAt = null,
        public readonly ?string $suppressedReason = null,
        public readonly ?string $lastError = null,
        public readonly ?string $providerMessageId = null,
        public readonly ?\DateTimeImmutable $sentAt = null,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?string $recipientAddressDigest = null,
    ) {
    }

    public function isIntent(): bool
    {
        return $this->state === QueueState::PendingFanout;
    }
}
