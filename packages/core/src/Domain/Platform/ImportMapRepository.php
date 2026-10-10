<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

interface ImportMapRepository
{
    /**
     * Records, or re-points, the local object an imported source object maps
     * to. Writing the same (importer, sourceType, sourceKey) triple twice
     * updates the existing row; it never adds a second one.
     */
    public function remember(
        string $importer,
        string $sourceType,
        string $sourceKey,
        string $localType,
        int $localId,
        int $jobId,
    ): void;

    public function find(string $importer, string $sourceType, string $sourceKey): ?ImportMapEntry;

    /**
     * @return list<ImportMapEntry>
     */
    public function forJob(int $jobId): array;

    /**
     * The provenance of one local object: which importer brought it in, and
     * under which source identity. Null when it was authored locally.
     */
    public function forLocal(string $localType, int $localId): ?ImportMapEntry;
}
