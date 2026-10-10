<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTreeCodec;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationRule;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * Rules and their channel child rows.
 *
 * The channels are a child table rather than a delimited column, because
 * "which rules use this channel" and "which rules break if this package is
 * removed" are asked from outside the rule and a delimited string answers
 * them with a scan.
 */
final class SqlNotificationRuleRepository
{
    public const TABLE = 'notification_rules';
    public const CHANNEL_TABLE = 'notification_rule_channels';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function find(int $id): ?NotificationRule
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('id', '=', $id));

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByKey(string $ruleKey): ?NotificationRule
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('rule_key', '=', $ruleKey));

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @return list<NotificationRule>
     */
    public function enabledForTrigger(string $triggerKey, ?int $courseId = null): array
    {
        $query = Query::from(self::TABLE)
            ->where('trigger_key', '=', $triggerKey)
            ->where('state', '=', NotificationRule::STATE_ENABLED)
            ->orderBy('id');

        $rows = $this->database->selectAll($query);
        $rules = [];

        foreach ($rows as $row) {
            $ruleCourse = $row['course_id'] === null ? null : (int) $row['course_id'];

            if ($ruleCourse !== null && $courseId !== null && $ruleCourse !== $courseId) {
                continue;
            }

            $rules[] = $this->hydrate($row);
        }

        return $rules;
    }

    /**
     * @return array{items: list<NotificationRule>, total: int}
     */
    public function page(?string $triggerKey, ?string $state, int $limit, int $offset): array
    {
        $filter = static function (Query $query) use ($triggerKey, $state): Query {
            if ($triggerKey !== null) {
                $query->where('trigger_key', '=', $triggerKey);
            }

            if ($state !== null) {
                $query->where('state', '=', $state);
            }

            return $query;
        };

        $rows = $this->database->selectAll(
            $filter(Query::from(self::TABLE))->orderBy('id')->limit($limit)->offset($offset),
        );

        return [
            'items' => array_map($this->hydrate(...), $rows),
            'total' => $this->database->count($filter(Query::from(self::TABLE))),
        ];
    }

    public function save(NotificationRule $rule): NotificationRule
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);
        $values = $this->values($rule, $now);

        $existing = $this->database->selectOne(
            Query::from(self::TABLE)->select(['id'])->where('rule_key', '=', $rule->ruleKey),
        );

        if ($existing === null) {
            $id = $this->database->insert(self::TABLE, $values + [
                'rule_key' => $rule->ruleKey,
                'queued_count' => 0,
                'sent_count' => 0,
                'suppressed_count' => 0,
                'created_at' => $now,
            ]);
        } else {
            $id = (int) $existing['id'];
            $this->database->update(self::TABLE, $values, ['id' => $id]);
        }

        $this->database->delete(self::CHANNEL_TABLE, ['rule_id' => $id]);

        foreach ($rule->channels as $channelKey) {
            $this->database->insert(self::CHANNEL_TABLE, ['rule_id' => $id, 'channel_key' => $channelKey]);
        }

        $saved = $this->find($id);

        return $saved ?? $rule;
    }

    /**
     * Installs one system default without ever updating an existing authored
     * rule. Concurrent seeders converge through a no-op unique-key upsert.
     */
    public function seedIfMissing(NotificationRule $rule): NotificationRule
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);
        $values = $this->values($rule, $now);

        return $this->database->transactional(function () use ($rule, $values, $now): NotificationRule {
            $this->database->upsert(
                self::TABLE,
                $values + [
                    'rule_key' => $rule->ruleKey,
                    'queued_count' => 0,
                    'sent_count' => 0,
                    'suppressed_count' => 0,
                    'created_at' => $now,
                ],
                ['rule_key'],
                [],
            );

            $row = $this->database->selectOne(
                Query::from(self::TABLE)->where('rule_key', '=', $rule->ruleKey),
            );

            if ($row === null) {
                throw new \RuntimeException('The seeded notification rule could not be read back.');
            }

            // Only a row identical to the system candidate may receive its
            // default channels. An operator-created winner is left byte for
            // byte alone, including its chosen channel set.
            if ($this->matchesSystemCandidate($row, $values)) {
                foreach ($rule->channels as $channelKey) {
                    $this->database->upsert(
                        self::CHANNEL_TABLE,
                        ['rule_id' => (int) $row['id'], 'channel_key' => $channelKey],
                        ['rule_id', 'channel_key'],
                        [],
                    );
                }
            }

            return $this->hydrate($row);
        });
    }

    public function delete(int $id): bool
    {
        $this->database->delete(self::CHANNEL_TABLE, ['rule_id' => $id]);

        return $this->database->delete(self::TABLE, ['id' => $id]) > 0;
    }

    /**
     * The per-rule counters are real columns so a site can measure what its
     * rules actually did, which is what makes shipping the machinery disabled
     * an honest default rather than a hidden one.
     */
    public function incrementCounter(int $id, string $column): void
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->select([$column])->where('id', '=', $id));

        if ($row === null) {
            return;
        }

        $this->database->update(self::TABLE, [$column => (int) ($row[$column] ?? 0) + 1], ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): NotificationRule
    {
        $id = (int) $row['id'];
        $channels = [];

        foreach (
            $this->database->selectAll(
                Query::from(self::CHANNEL_TABLE)->select(['channel_key'])->where('rule_id', '=', $id),
            ) as $channel
        ) {
            $channels[] = (string) $channel['channel_key'];
        }

        $conditions = $this->decode($row['conditions'] ?? null);
        $updatedAt = $row['updated_at'] ?? null;

        return new NotificationRule(
            $id,
            (string) $row['rule_key'],
            (string) $row['trigger_key'],
            (string) $row['audience_key'],
            (string) $row['category_key'],
            (string) $row['template_key'],
            $channels,
            (string) ($row['schedule_kind'] ?? NotificationRule::KIND_IMMEDIATE),
            $row['anchor_key'] === null ? null : (string) $row['anchor_key'],
            StoredShape::bodyOf($this->decode($row['schedule'] ?? null) ?? []),
            StoredShape::bodyOf($this->decode($row['definition'] ?? null) ?? []),
            StoredShape::bodyOf($this->decode($row['audience_config'] ?? null) ?? []),
            $conditions === null ? null : AvailabilityTreeCodec::decode($conditions),
            $this->stringList($row['suppresses'] ?? null),
            $row['course_id'] === null ? null : (int) $row['course_id'],
            $row['cohort_id'] === null ? null : (int) $row['cohort_id'],
            (string) ($row['state'] ?? NotificationRule::STATE_DISABLED),
            (int) ($row['queued_count'] ?? 0),
            (int) ($row['sent_count'] ?? 0),
            (int) ($row['suppressed_count'] ?? 0),
            $row['created_by'] === null ? null : (int) $row['created_by'],
            \is_string($updatedAt) && $updatedAt !== ''
                ? new \DateTimeImmutable($updatedAt, new \DateTimeZone('UTC'))
                : null,
        );
    }

    /** @return array<string, mixed> */
    private function values(NotificationRule $rule, string $now): array
    {
        $conditions = $rule->conditions === null ? null : AvailabilityTreeCodec::encode($rule->conditions);

        return [
            'trigger_key' => $rule->triggerKey,
            'definition' => $rule->definition === []
                ? null
                : json_encode(StoredShape::wrap($rule->definition, 1), \JSON_THROW_ON_ERROR),
            'definition_version' => 1,
            'conditions' => $conditions === null ? null : json_encode($conditions, \JSON_THROW_ON_ERROR),
            'conditions_version' => 1,
            'audience_key' => $rule->audienceKey,
            'audience_config' => $rule->audienceConfig === []
                ? null
                : json_encode(StoredShape::wrap($rule->audienceConfig, 1), \JSON_THROW_ON_ERROR),
            'audience_config_version' => 1,
            'category_key' => $rule->categoryKey,
            'template_key' => $rule->templateKey,
            'schedule_kind' => $rule->scheduleKind,
            'anchor_key' => $rule->anchorKey,
            'schedule' => $rule->schedule === []
                ? null
                : json_encode(StoredShape::wrap($rule->schedule, 1), \JSON_THROW_ON_ERROR),
            'schedule_version' => 1,
            'suppresses' => $rule->suppresses === [] ? null : json_encode($rule->suppresses, \JSON_THROW_ON_ERROR),
            'course_id' => $rule->courseId,
            'cohort_id' => $rule->cohortId,
            'state' => $rule->state,
            'created_by' => $rule->createdBy,
            'updated_at' => $now,
        ];
    }

    /** @param array<string, mixed> $row
     * @param array<string, mixed> $candidate
     */
    private function matchesSystemCandidate(array $row, array $candidate): bool
    {
        if (($row['created_by'] ?? null) !== null) {
            return false;
        }

        foreach ($candidate as $column => $value) {
            if ($column === 'updated_at') {
                continue;
            }

            $stored = $row[$column] ?? null;

            if ($value === null ? $stored !== null : (string) $stored !== (string) $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(mixed $raw): ?array
    {
        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        /** @var array<string, mixed>|null $result */
        $result = \is_array($decoded) ? $decoded : null;

        return $result;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (!\is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            return [];
        }

        $values = [];

        foreach ($decoded as $value) {
            if (\is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
