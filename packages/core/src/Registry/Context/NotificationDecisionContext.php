<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * What a policy may read when it decides.
 *
 * Audience, conditions and the frequency cap are all re-resolved at send and
 * never snapshotted at enqueue, because any delay measured in days can
 * outlive the reason for the message. The queue row stores intent; this
 * context is assembled immediately before the channel is called.
 *
 * The recipient timezone is stamped on the queue row at enqueue rather than
 * read here, so a later timezone change cannot resurrect a past send, and it
 * is defaulted to the site timezone rather than to the literal UTC: a UTC
 * 08:00 to 21:00 window is 03:00 to 16:00 US Eastern, and a literal default
 * would breach the statute no rule may defeat.
 */
final class NotificationDecisionContext
{
    /**
     * @param list<string>         $channelKeys the channels still in play at this point in the fold
     * @param array<string, mixed> $settings    the deciding policy's own provider_settings params
     */
    private function __construct(
        public readonly \DateTimeImmutable $now,
        public readonly DatabasePort $database,
        public readonly array $channelKeys,
        public readonly array $settings,
        public readonly string $recipientTimezone,
        public readonly ?string $recipientAddressDigest,
        public readonly ?int $ruleId,
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly ?int $queueId,
        public readonly string $triggerKey,
    ) {
    }

    /**
     * @param list<string>         $channelKeys
     * @param array<string, mixed> $settings
     */
    public static function create(
        \DateTimeImmutable $now,
        DatabasePort $database,
        array $channelKeys,
        array $settings = [],
        string $recipientTimezone = 'UTC',
        ?string $recipientAddressDigest = null,
        ?int $ruleId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $queueId = null,
        string $triggerKey = '',
    ): self {
        return new self(
            $now,
            $database,
            $channelKeys,
            $settings,
            $recipientTimezone === '' ? 'UTC' : $recipientTimezone,
            $recipientAddressDigest,
            $ruleId,
            $subjectType,
            $subjectId,
            $queueId,
            $triggerKey,
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function withSettings(array $settings): self
    {
        return new self(
            $this->now,
            $this->database,
            $this->channelKeys,
            $settings,
            $this->recipientTimezone,
            $this->recipientAddressDigest,
            $this->ruleId,
            $this->subjectType,
            $this->subjectId,
            $this->queueId,
            $this->triggerKey,
        );
    }

    public function intSetting(string $key, int $default): int
    {
        $value = $this->settings[$key] ?? null;

        if (\is_int($value)) {
            return $value;
        }

        return \is_string($value) && preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $default;
    }

    public function stringSetting(string $key, string $default): string
    {
        $value = $this->settings[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    public function boolSetting(string $key, bool $default): bool
    {
        $value = $this->settings[$key] ?? null;

        return \is_bool($value) ? $value : $default;
    }
}
