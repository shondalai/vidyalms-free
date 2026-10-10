<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

/**
 * One row of a job's manifest: this source produced that local thing.
 */
final class AiJobMapEntry
{
    public function __construct(
        public readonly int $jobId,
        public readonly string $sourceType,
        public readonly string $sourceKey,
        public readonly string $localType,
        public readonly int $localId,
    ) {
    }

    /**
     * The uniqueness carrier for (source_type, source_key).
     *
     * Derived in one place so the write and the lookup cannot disagree, and
     * spelled with a separator that cannot appear in a type name, so
     * ("a|b", "c") and ("a", "b|c") are different keys rather than the same one.
     */
    public static function digestFor(string $sourceType, string $sourceKey): string
    {
        return hash('sha256', \strlen($sourceType) . '|' . $sourceType . '|' . $sourceKey);
    }

    public function digest(): string
    {
        return self::digestFor($this->sourceType, $this->sourceKey);
    }
}
