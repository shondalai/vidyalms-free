<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform;

use Shondalai\VidyaLMS\Core\Domain\Platform\SchemaVersionRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class DatabaseSchemaVersionRepository implements SchemaVersionRepository
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function recordOnce(string $version): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->database->upsert(
            'schema_version',
            [
                'version' => $version,
                'applied_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['version'],
            [],
        );
    }

    public function has(string $version): bool
    {
        return $this->database->count(
            Query::from('schema_version')->where('version', '=', $version),
        ) > 0;
    }
}
