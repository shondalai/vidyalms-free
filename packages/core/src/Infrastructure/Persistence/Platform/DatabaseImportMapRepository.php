<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform;

use Shondalai\VidyaLMS\Core\Domain\Platform\ImportMapEntry;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportMapRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class DatabaseImportMapRepository implements ImportMapRepository
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function remember(
        string $importer,
        string $sourceType,
        string $sourceKey,
        string $localType,
        int $localId,
        int $jobId,
    ): void {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->database->upsert(
            'import_map',
            [
                'importer' => $importer,
                'source_type' => $sourceType,
                'source_key' => $sourceKey,
                'local_type' => $localType,
                'local_id' => $localId,
                'job_id' => $jobId,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['importer', 'source_type', 'source_key'],
            ['local_type', 'local_id', 'job_id', 'updated_at'],
        );
    }

    public function find(string $importer, string $sourceType, string $sourceKey): ?ImportMapEntry
    {
        $row = $this->database->selectOne(
            Query::from('import_map')
                ->where('importer', '=', $importer)
                ->where('source_type', '=', $sourceType)
                ->where('source_key', '=', $sourceKey),
        );

        return $row === null ? null : $this->map($row);
    }

    public function forJob(int $jobId): array
    {
        $rows = $this->database->selectAll(
            Query::from('import_map')
                ->where('job_id', '=', $jobId)
                ->orderBy('id'),
        );

        return array_map($this->map(...), $rows);
    }

    public function forLocal(string $localType, int $localId): ?ImportMapEntry
    {
        $row = $this->database->selectOne(
            Query::from('import_map')
                ->where('local_type', '=', $localType)
                ->where('local_id', '=', $localId)
                ->orderBy('id', Query::SORT_DESC)
                ->limit(1),
        );

        return $row === null ? null : $this->map($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): ImportMapEntry
    {
        return new ImportMapEntry(
            (int) ($row['id'] ?? 0),
            (string) ($row['importer'] ?? ''),
            (string) ($row['source_type'] ?? ''),
            (string) ($row['source_key'] ?? ''),
            (string) ($row['local_type'] ?? ''),
            (int) ($row['local_id'] ?? 0),
            (int) ($row['job_id'] ?? 0),
        );
    }
}
