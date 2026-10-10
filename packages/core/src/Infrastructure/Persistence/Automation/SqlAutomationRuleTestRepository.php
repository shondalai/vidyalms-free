<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation;

use Shondalai\VidyaLMS\Core\Domain\Automation\StoredRuleTest;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * Stored rehearsals, one row per named test of one rule version.
 *
 * All three documents are StoredShape-wrapped with their version mirrored into
 * a real column, because a provider shape with no version is a shape nobody
 * can upgrade, and because a batch upgrade of stored tests should be an index
 * scan rather than a JSON path query.
 */
final class SqlAutomationRuleTestRepository
{
    public const TABLE = 'automation_rule_tests';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function save(StoredRuleTest $test): int
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $values = [
            'rule_id' => $test->ruleId,
            'rule_version' => $test->ruleVersion,
            'name' => $test->name,
            'given' => json_encode(StoredShape::wrap($test->given, $test->givenVersion), \JSON_THROW_ON_ERROR),
            'given_version' => $test->givenVersion,
            'changed' => json_encode(StoredShape::wrap($test->changed, $test->changedVersion), \JSON_THROW_ON_ERROR),
            'changed_version' => $test->changedVersion,
            'expectations' => json_encode(
                StoredShape::wrap($test->expectations, $test->expectationsVersion),
                \JSON_THROW_ON_ERROR,
            ),
            'expectations_version' => $test->expectationsVersion,
            'updated_at' => $now,
        ];

        $existing = $this->database->selectOne(
            Query::from(self::TABLE)
                ->select(['id'])
                ->where('rule_id', '=', $test->ruleId)
                ->where('name', '=', $test->name),
        );

        if ($existing !== null) {
            $id = (int) $existing['id'];
            $this->database->update(self::TABLE, $values, ['id' => $id]);

            return $id;
        }

        $values['created_by'] = $test->createdBy;
        $values['created_at'] = $now;

        return $this->database->insert(self::TABLE, $values);
    }

    public function find(int $id): ?StoredRuleTest
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('id', '=', $id));

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * @return list<StoredRuleTest>
     */
    public function forRule(int $ruleId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)->where('rule_id', '=', $ruleId)->orderBy('name'),
        );

        return array_map(static fn (array $row): StoredRuleTest => self::hydrate($row), $rows);
    }

    /**
     * The badge the rules screen reads, which is why last_result is a real
     * column: a JSON scan cannot drive a list badge.
     */
    public function recordResult(int $id, string $result, string $detail): void
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->database->update(self::TABLE, [
            'last_run_at' => $now,
            'last_result' => $result,
            'last_detail' => $detail,
            'updated_at' => $now,
        ], ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): StoredRuleTest
    {
        $lastRunAt = $row['last_run_at'] ?? null;

        return new StoredRuleTest(
            (int) $row['id'],
            (int) $row['rule_id'],
            (int) ($row['rule_version'] ?? 1),
            (string) $row['name'],
            self::decode($row['given'] ?? null),
            self::decode($row['changed'] ?? null),
            self::decode($row['expectations'] ?? null),
            (int) ($row['created_by'] ?? 0),
            \is_string($lastRunAt) && $lastRunAt !== ''
                ? new \DateTimeImmutable($lastRunAt, new \DateTimeZone('UTC'))
                : null,
            $row['last_result'] === null ? null : (string) $row['last_result'],
            $row['last_detail'] === null ? null : (string) $row['last_detail'],
            (int) ($row['given_version'] ?? 1),
            (int) ($row['changed_version'] ?? 1),
            (int) ($row['expectations_version'] ?? 1),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(mixed $value): array
    {
        if (\is_array($value)) {
            return StoredShape::bodyOf($value);
        }

        if (!\is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return \is_array($decoded) ? StoredShape::bodyOf($decoded) : [];
    }
}
