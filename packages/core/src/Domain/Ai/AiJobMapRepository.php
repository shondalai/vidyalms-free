<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

/**
 * import_map's analogue for AI jobs, and for the same reason.
 *
 * It is what makes a resumed or re-run build an update rather than a second set
 * of drafts, and read the other way it answers which syllabus section a
 * generated module came from.
 *
 * The uniqueness is carried on a digest of the source pair rather than on the
 * pair itself, because (job_id, source_type, source_key) is past the 767-byte
 * key limit of the legacy InnoDB row formats. Callers pass the natural pair and
 * never the digest: the derivation belongs to the implementation, so two
 * implementations cannot disagree about it.
 */
interface AiJobMapRepository
{
    /**
     * Remembers that a source produced a local row, or updates the row it
     * produced last time.
     */
    public function remember(
        int $jobId,
        string $sourceType,
        string $sourceKey,
        string $localType,
        int $localId,
    ): void;

    /**
     * What this source produced under this job, or null when it is new.
     */
    public function resolve(int $jobId, string $sourceType, string $sourceKey): ?AiJobMapEntry;

    /**
     * Everything one job mapped, in insertion order.
     *
     * @return list<AiJobMapEntry>
     */
    public function forJob(int $jobId): array;

    /**
     * Which source a local row came from, read the other way.
     *
     * @return list<AiJobMapEntry>
     */
    public function forLocal(string $localType, int $localId): array;
}
