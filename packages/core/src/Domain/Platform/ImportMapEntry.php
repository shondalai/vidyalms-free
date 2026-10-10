<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

/**
 * One thing an importer created, tied to the identity it had in the source
 * system. Read forwards it answers "have I already imported this?"; read
 * backwards it answers "where did this course come from?".
 */
final class ImportMapEntry
{
    public function __construct(
        public readonly int $id,
        public readonly string $importer,
        public readonly string $sourceType,
        public readonly string $sourceKey,
        public readonly string $localType,
        public readonly int $localId,
        public readonly int $jobId,
    ) {
    }
}
