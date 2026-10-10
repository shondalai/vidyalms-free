<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification;

use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationDigest;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * A deliverability fact about an address, binding every message class
 * including the ones a site must send.
 *
 * There is deliberately no unsubscribed-all reason. A category opt-out writes
 * a preference row and a consent row and never lands here, or the next
 * certificate mail, which is transactional and which a site may be obliged to
 * deliver, would be silently suppressed. That asymmetry is the point.
 *
 * The address is stored as a digest and never in plaintext, so the
 * suppression list is not a second copy of the mailing list, and it is per
 * address rather than per user because one person's second address is a
 * different deliverability fact.
 */
final class SqlNotificationSuppressionRepository
{
    public const REASON_HARD_BOUNCE = 'hard_bounce';
    public const REASON_COMPLAINT = 'complaint';
    public const REASON_MANUAL = 'manual';

    /** How many soft bounces suppress an address, unless a setting says otherwise. */
    public const DEFAULT_SOFT_BOUNCE_THRESHOLD = 5;

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function isSuppressed(string $channelKey, string $addressDigest): bool
    {
        $row = $this->database->selectOne(
            Query::from('notification_suppressions')
                ->select(['id', 'released_at'])
                ->where('channel_key', '=', $channelKey)
                ->where('address_digest', '=', $addressDigest),
        );

        return $row !== null && $row['released_at'] === null;
    }

    public function suppress(string $channelKey, string $addressDigest, string $reason, ?string $diagnostic = null): void
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->database->upsert(
            'notification_suppressions',
            [
                'channel_key' => $channelKey,
                'address_digest' => $addressDigest,
                'reason' => $reason,
                'soft_bounce_count' => 0,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'released_at' => null,
                'diagnostic' => $diagnostic,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['channel_key', 'address_digest'],
            ['reason', 'last_seen_at', 'released_at', 'diagnostic', 'updated_at'],
        );
    }

    /**
     * A soft bounce increments and suppresses at a threshold: one deferral is
     * not a deliverability fact.
     */
    public function recordSoftBounce(string $channelKey, string $addressDigest, int $threshold, ?string $diagnostic): bool
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $row = $this->database->selectOne(
            Query::from('notification_suppressions')
                ->where('channel_key', '=', $channelKey)
                ->where('address_digest', '=', $addressDigest),
        );

        if ($row === null) {
            $this->database->insert('notification_suppressions', [
                'channel_key' => $channelKey,
                'address_digest' => $addressDigest,
                'reason' => self::REASON_HARD_BOUNCE,
                'soft_bounce_count' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'released_at' => $now,
                'diagnostic' => $diagnostic,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return 1 >= $threshold;
        }

        $count = (int) ($row['soft_bounce_count'] ?? 0) + 1;
        $suppresses = $count >= $threshold;

        $this->database->update('notification_suppressions', [
            'soft_bounce_count' => $count,
            'last_seen_at' => $now,
            'released_at' => $suppresses ? null : ($row['released_at'] ?? $now),
            'diagnostic' => $diagnostic,
            'updated_at' => $now,
        ], ['id' => (int) $row['id']]);

        return $suppresses;
    }

    public function release(string $channelKey, string $addressDigest): bool
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        return $this->database->update('notification_suppressions', [
            'released_at' => $now,
            'updated_at' => $now,
        ], ['channel_key' => $channelKey, 'address_digest' => $addressDigest]) > 0;
    }

    public function digestOf(string $address): string
    {
        return NotificationDigest::forAddress($address);
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function page(?string $channelKey, ?string $addressDigest, int $limit, int $offset): array
    {
        $filter = static function (Query $query) use ($channelKey, $addressDigest): Query {
            if ($channelKey !== null) {
                $query->where('channel_key', '=', $channelKey);
            }

            if ($addressDigest !== null) {
                $query->where('address_digest', '=', $addressDigest);
            }

            return $query;
        };

        return [
            'items' => $this->database->selectAll(
                $filter(Query::from('notification_suppressions'))
                    ->orderBy('id', Query::SORT_DESC)
                    ->limit($limit)
                    ->offset($offset),
            ),
            'total' => $this->database->count($filter(Query::from('notification_suppressions'))),
        ];
    }
}
