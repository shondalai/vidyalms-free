<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportMap;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseImportMapRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The memory that makes a re-run an update. Every case here is a thing that
 * would otherwise duplicate a customer's course.
 */
final class ImportMapTest extends TestCase
{
    public function testRememberingTheSameSourceObjectTwiceUpdatesTheOneRow(): void
    {
        $env = TestPorts::create();
        $map = $this->map($env);

        $map->remember('moodle', 'activity', '4711', 'lesson', 12, 1);
        $map->remember('moodle', 'activity', '4711', 'lesson', 12, 2);

        self::assertSame(1, $env->database->count(Query::from('import_map')));
        self::assertSame(12, $map->lookup('moodle', 'activity', '4711'));

        $entry = $map->entry('moodle', 'activity', '4711');
        self::assertNotNull($entry);
        self::assertSame(2, $entry->jobId, 'The latest run owns the mapping.');
    }

    public function testRePointingAMappingKeepsOneRowAndFollowsTheNewLocalObject(): void
    {
        $env = TestPorts::create();
        $map = $this->map($env);

        $map->remember('community-quiz', 'quiz', '9', 'quiz', 3, 1);
        $map->remember('community-quiz', 'quiz', '9', 'quiz', 88, 1);

        self::assertSame(1, $env->database->count(Query::from('import_map')));
        self::assertSame(88, $map->lookup('community-quiz', 'quiz', '9'));
    }

    public function testTheSameSourceKeyUnderDifferentImportersIsADifferentThing(): void
    {
        $env = TestPorts::create();
        $map = $this->map($env);

        $map->remember('moodle', 'course', '1', 'course', 10, 1);
        $map->remember('community-quiz', 'course', '1', 'course', 20, 1);

        self::assertSame(10, $map->lookup('moodle', 'course', '1'));
        self::assertSame(20, $map->lookup('community-quiz', 'course', '1'));
        self::assertSame(2, $env->database->count(Query::from('import_map')));
    }

    public function testAnUnknownSourceObjectLooksUpAsNull(): void
    {
        $map = $this->map(TestPorts::create());

        self::assertNull($map->lookup('moodle', 'course', 'never-seen'));
        self::assertNull($map->entry('moodle', 'course', '   '));
    }

    public function testAJobManifestListsWhatThatRunTouched(): void
    {
        $map = $this->map(TestPorts::create());

        $map->remember('moodle', 'course', '1', 'course', 10, 7);
        $map->remember('moodle', 'section', '2', 'module', 11, 7);
        $map->remember('moodle', 'course', '3', 'course', 12, 8);

        $manifest = $map->forJob(7);

        self::assertCount(2, $manifest);
        self::assertSame(['course', 'module'], array_map(static fn ($entry): string => $entry->localType, $manifest));
        self::assertSame([], $map->forJob(0));
    }

    public function testProvenanceAnswersWhereACourseCameFrom(): void
    {
        $map = $this->map(TestPorts::create());

        $map->remember('moodle', 'course', 'course-4711', 'course', 10, 7);

        $entry = $map->provenanceOf('course', 10);

        self::assertNotNull($entry);
        self::assertSame('moodle', $entry->importer);
        self::assertSame('course-4711', $entry->sourceKey);
        self::assertSame(7, $entry->jobId);
        self::assertNull($map->provenanceOf('course', 99), 'A locally authored course has no provenance.');
    }

    public function testAnOverLongSourceKeyIsFoldedConsistentlyOnBothSides(): void
    {
        $env = TestPorts::create();
        $map = $this->map($env);
        $long = 'moodle/backup/' . str_repeat('section-and-activity-path/', 20) . 'page.xml';

        self::assertGreaterThan(190, \strlen($long));

        $map->remember('moodle', 'activity', $long, 'lesson', 5, 1);

        self::assertSame(5, $map->lookup('moodle', 'activity', $long));

        $row = $env->database->selectOne(Query::from('import_map'));
        self::assertNotNull($row);
        self::assertIsString($row['source_key']);
        self::assertSame(190, \strlen($row['source_key']));
    }

    public function testAMappingNeedsARealSourceKeyAndLocalId(): void
    {
        $map = $this->map(TestPorts::create());

        $this->expectException(ValidationException::class);
        $map->remember('moodle', 'course', '  ', 'course', 1, 1);
    }

    public function testAMappingNeedsAPositiveLocalId(): void
    {
        $map = $this->map(TestPorts::create());

        try {
            $map->remember('moodle', 'course', '1', 'course', 0, 1);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('local_id', $exception->errors);
        }
    }

    private function map(TestPorts $env): ImportMap
    {
        return new ImportMap(new DatabaseImportMapRepository($env->database, $env->clock));
    }
}
