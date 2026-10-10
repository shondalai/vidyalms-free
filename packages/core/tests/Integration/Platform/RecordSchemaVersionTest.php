<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\RecordSchemaVersion;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSchemaVersionRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class RecordSchemaVersionTest extends TestCase
{
    public function testRecordsAVersionOnce(): void
    {
        $env = TestPorts::create();
        $repository = new DatabaseSchemaVersionRepository($env->database, $env->clock);

        (new RecordSchemaVersion($repository))->execute('1.0.0');

        self::assertTrue($repository->has('1.0.0'));
        $row = $env->database->selectOne(Query::from('schema_version'));
        self::assertNotNull($row);
        self::assertSame('1.0.0', $row['version']);
        self::assertSame('2026-01-15 10:00:00', $row['applied_at']);
    }

    public function testDuplicateCallsAreNoOps(): void
    {
        $env = TestPorts::create();
        $repository = new DatabaseSchemaVersionRepository($env->database, $env->clock);
        $useCase = new RecordSchemaVersion($repository);

        $useCase->execute('1.0.0');
        $env->clock->advance('PT1H');
        $useCase->execute('1.0.0');

        self::assertSame(1, $env->database->count(Query::from('schema_version')));
        $row = $env->database->selectOne(Query::from('schema_version'));
        self::assertNotNull($row);
        // The original applied_at survives the replay.
        self::assertSame('2026-01-15 10:00:00', $row['applied_at']);
    }

    public function testDistinctVersionsEachGetARow(): void
    {
        $env = TestPorts::create();
        $useCase = new RecordSchemaVersion(new DatabaseSchemaVersionRepository($env->database, $env->clock));

        $useCase->execute('1.0.0');
        $useCase->execute('1.1.0');

        self::assertSame(2, $env->database->count(Query::from('schema_version')));
    }

    public function testABlankVersionIsRejected(): void
    {
        $env = TestPorts::create();
        $useCase = new RecordSchemaVersion(new DatabaseSchemaVersionRepository($env->database, $env->clock));

        $this->expectException(ValidationException::class);
        $useCase->execute(' ');
    }
}
