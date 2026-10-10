<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * User preference and site policy: different aggregates, different
 * lifecycles, different legal weight, and conflating them is the common bug.
 *
 * A preference is (user, category, channel) to a frequency; a policy is
 * (category, channel) to disallowed, permitted or forced. Resolution is a
 * pure function over the two plus the message class and lives beside the
 * completion engine as a second pure engine rather than inside any channel.
 */
final class SqlNotificationPreferenceRepository
{
    public const FREQUENCY_IMMEDIATE = 'immediate';
    public const FREQUENCY_DAILY = 'daily';
    public const FREQUENCY_WEEKLY = 'weekly';
    public const FREQUENCY_NEVER = 'never';

    public const POLICY_DISALLOWED = 'disallowed';
    public const POLICY_PERMITTED = 'permitted';
    public const POLICY_FORCED = 'forced';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function frequencies(): array
    {
        return [
            self::FREQUENCY_IMMEDIATE,
            self::FREQUENCY_DAILY,
            self::FREQUENCY_WEEKLY,
            self::FREQUENCY_NEVER,
        ];
    }

    /**
     * @return list<string>
     */
    public static function policyStates(): array
    {
        return [self::POLICY_DISALLOWED, self::POLICY_PERMITTED, self::POLICY_FORCED];
    }

    /**
     * @return array<string, string> "category|channel" to frequency
     */
    public function preferencesOf(int $userId): array
    {
        $rows = $this->database->selectAll(
            Query::from('notification_preferences')
                ->select(['category_key', 'channel_key', 'frequency'])
                ->where('user_id', '=', $userId),
        );

        $map = [];

        foreach ($rows as $row) {
            $map[$row['category_key'] . '|' . $row['channel_key']] = (string) $row['frequency'];
        }

        return $map;
    }

    public function frequencyFor(int $userId, string $categoryKey, string $channelKey): ?string
    {
        $row = $this->database->selectOne(
            Query::from('notification_preferences')
                ->select(['frequency'])
                ->where('user_id', '=', $userId)
                ->where('category_key', '=', $categoryKey)
                ->where('channel_key', '=', $channelKey),
        );

        return $row === null ? null : (string) $row['frequency'];
    }

    public function setPreference(int $userId, string $categoryKey, string $channelKey, string $frequency): void
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->database->upsert(
            'notification_preferences',
            [
                'user_id' => $userId,
                'category_key' => $categoryKey,
                'channel_key' => $channelKey,
                'frequency' => $frequency,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['user_id', 'category_key', 'channel_key'],
            ['frequency', 'updated_at'],
        );
    }

    /**
     * @return array<string, array{state: string, default_frequency: string}> "category|channel" to the cell
     */
    public function policies(): array
    {
        $rows = $this->database->selectAll(
            Query::from('notification_policies')->select(['category_key', 'channel_key', 'state', 'default_frequency']),
        );

        $map = [];

        foreach ($rows as $row) {
            $map[$row['category_key'] . '|' . $row['channel_key']] = [
                'state' => (string) $row['state'],
                'default_frequency' => (string) $row['default_frequency'],
            ];
        }

        return $map;
    }

    public function setPolicy(string $categoryKey, string $channelKey, string $state, string $defaultFrequency): void
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->database->upsert(
            'notification_policies',
            [
                'category_key' => $categoryKey,
                'channel_key' => $channelKey,
                'state' => $state,
                'default_frequency' => $defaultFrequency,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['category_key', 'channel_key'],
            ['state', 'default_frequency', 'updated_at'],
        );
    }
}
