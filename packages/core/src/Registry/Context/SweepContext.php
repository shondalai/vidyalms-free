<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * What a sweep trigger may read: the clock, a database handle, the rule's own
 * configuration, and the shard it is running.
 *
 * The bin index and bin count are the sharding, so one run enumerates a
 * bounded slice rather than every enrolment on a large site, and the period
 * bucket is what makes the occurrence key stable across two runs inside one
 * period. A sweep that ignores the bucket sends twice when a cron fires
 * twice.
 */
final class SweepContext
{
    public const DEFAULT_BIN_COUNT = 24;

    /**
     * @param array<string, mixed> $definition
     */
    private function __construct(
        public readonly \DateTimeImmutable $now,
        public readonly DatabasePort $database,
        public readonly array $definition,
        public readonly int $binIndex,
        public readonly int $binCount,
        public readonly string $periodBucket,
        public readonly ?int $ruleId,
        public readonly string $siteTimezone,
    ) {
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function create(
        \DateTimeImmutable $now,
        DatabasePort $database,
        array $definition = [],
        int $binIndex = 0,
        int $binCount = self::DEFAULT_BIN_COUNT,
        ?string $periodBucket = null,
        ?int $ruleId = null,
        string $siteTimezone = 'UTC',
    ): self {
        $count = max(1, $binCount);

        return new self(
            $now,
            $database,
            $definition,
            max(0, min($binIndex, $count - 1)),
            $count,
            $periodBucket ?? $now->format('Y-m-d'),
            $ruleId,
            $siteTimezone,
        );
    }

    /**
     * Whether an id belongs to the shard this run owns. Modulo on the id
     * keeps a learner in the same bin between runs, so a bounded run is
     * still a complete run across the set.
     */
    public function ownsShard(int $id): bool
    {
        return $id % $this->binCount === $this->binIndex;
    }

    public function intSetting(string $key, int $default): int
    {
        $value = $this->definition[$key] ?? null;

        return \is_int($value) || (\is_string($value) && preg_match('/^-?\d+$/', $value) === 1)
            ? (int) $value
            : $default;
    }
}
