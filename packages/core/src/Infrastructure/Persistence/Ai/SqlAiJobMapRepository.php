<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai;

use Shondalai\VidyaLMS\Core\Domain\Ai\AiJobMapEntry;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiJobMapRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The job manifest, over the DatabasePort fluent API.
 *
 * The uniqueness is carried on a digest of the source pair, because the natural
 * triple is past the 767-byte key limit of the legacy InnoDB row formats. The
 * digest is derived in AiJobMapEntry and nowhere else, so the write and the
 * lookup cannot disagree about it, and the natural columns are stored beside it
 * because "which syllabus section did this module come from" is a question asked
 * of the row rather than of the hash.
 */
final class SqlAiJobMapRepository implements AiJobMapRepository
{
    private const MAP = 'ai_job_map';

    public function __construct(private readonly DatabasePort $database)
    {
    }

    public function remember(
        int $jobId,
        string $sourceType,
        string $sourceKey,
        string $localType,
        int $localId,
    ): void {
        $this->database->upsert(self::MAP, [
            'job_id' => $jobId,
            'source_type' => $sourceType,
            'source_key' => $sourceKey,
            'source_digest' => AiJobMapEntry::digestFor($sourceType, $sourceKey),
            'local_type' => $localType,
            'local_id' => $localId,
        ], ['job_id', 'source_digest'], ['source_type', 'source_key', 'local_type', 'local_id']);
    }

    public function resolve(int $jobId, string $sourceType, string $sourceKey): ?AiJobMapEntry
    {
        $row = $this->database->selectOne(
            Query::from(self::MAP)
                ->where('job_id', '=', $jobId)
                ->where('source_digest', '=', AiJobMapEntry::digestFor($sourceType, $sourceKey)),
        );

        return $row === null ? null : $this->map($row);
    }

    public function forJob(int $jobId): array
    {
        return array_map($this->map(...), $this->database->selectAll(
            Query::from(self::MAP)->where('job_id', '=', $jobId)->orderBy('id', Query::SORT_ASC),
        ));
    }

    public function forLocal(string $localType, int $localId): array
    {
        return array_map($this->map(...), $this->database->selectAll(
            Query::from(self::MAP)
                ->where('local_type', '=', $localType)
                ->where('local_id', '=', $localId)
                ->orderBy('id', Query::SORT_ASC),
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): AiJobMapEntry
    {
        return new AiJobMapEntry(
            (int) $row['job_id'],
            (string) $row['source_type'],
            (string) $row['source_key'],
            (string) $row['local_type'],
            (int) $row['local_id'],
        );
    }
}
