<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Support;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class SqliteHarnessTest extends TestCase
{
    public function testSchemaLoadsAndInstallsEveryTable(): void
    {
        $schema = SchemaDefinition::load();
        $env = TestPorts::create();

        foreach ($schema->tableNames() as $table) {
            self::assertSame(0, $env->database->count(Query::from($table)), $table);
        }

        self::assertContains('courses', $schema->tableNames());
        self::assertContains('enrolments', $schema->tableNames());
        self::assertContains('idempotency_keys', $schema->tableNames());
    }

    public function testInsertSelectUpdateDeleteRoundTrip(): void
    {
        $env = TestPorts::create();
        $now = $env->clock->now()->format('Y-m-d H:i:s');

        $id = $env->database->insert('courses', [
            'title' => 'Intro to Joomla',
            'slug' => 'intro-to-joomla',
            'created_by' => 42,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        self::assertSame(1, $id);

        $row = $env->database->selectOne(
            Query::from('courses')->where('slug', '=', 'intro-to-joomla'),
        );

        self::assertNotNull($row);
        self::assertSame('Intro to Joomla', $row['title']);
        self::assertSame('draft', $row['state']);
        self::assertSame('all_lessons', $row['completion_rule']);

        $affected = $env->database->update('courses', ['state' => 'published'], ['id' => $id]);
        self::assertSame(1, $affected);

        self::assertSame(1, $env->database->count(Query::from('courses')->where('state', '=', 'published')));
        self::assertSame(1, $env->database->delete('courses', ['id' => $id]));
        self::assertSame(0, $env->database->count(Query::from('courses')));
    }

    public function testUpsertIsIdempotentLikeAHeartbeat(): void
    {
        $env = TestPorts::create();
        $now = $env->clock->now()->format('Y-m-d H:i:s');

        $base = [
            'enrolment_id' => 7,
            'lesson_id' => 3,
            'user_id' => 42,
            'status' => 'in_progress',
            'progress_percent' => 40,
            'seconds_spent' => 60,
            'last_heartbeat_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $updateColumns = ['progress_percent', 'seconds_spent', 'last_heartbeat_at', 'updated_at'];

        $env->database->upsert('lesson_progress', $base, ['enrolment_id', 'lesson_id'], $updateColumns);
        $env->database->upsert(
            'lesson_progress',
            array_merge($base, ['progress_percent' => 55, 'seconds_spent' => 75]),
            ['enrolment_id', 'lesson_id'],
            $updateColumns,
        );

        self::assertSame(1, $env->database->count(Query::from('lesson_progress')));

        $row = $env->database->selectOne(Query::from('lesson_progress')->where('enrolment_id', '=', 7));
        self::assertNotNull($row);
        self::assertEquals(55, $row['progress_percent']);
        self::assertEquals(75, $row['seconds_spent']);
    }

    public function testTransactionRollsBackOnFailure(): void
    {
        $env = TestPorts::create();
        $now = $env->clock->now()->format('Y-m-d H:i:s');

        try {
            $env->database->transactional(function () use ($env, $now): void {
                $env->database->insert('courses', [
                    'title' => 'Doomed',
                    'slug' => 'doomed',
                    'created_by' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException $exception) {
            self::assertSame('boom', $exception->getMessage());
        }

        // The rollback must have discarded the insert made inside the closure.
        self::assertSame(0, $env->database->count(Query::from('courses')));
    }

    public function testUniqueIndexesAreEnforced(): void
    {
        $env = TestPorts::create();
        $now = $env->clock->now()->format('Y-m-d H:i:s');

        $course = [
            'title' => 'A',
            'slug' => 'same-slug',
            'created_by' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $env->database->insert('courses', $course);

        try {
            $env->database->insert('courses', $course);
            self::fail('the unique index on courses.slug was not enforced');
        } catch (UniqueConstraintViolation $violation) {
            // The harness maps its driver's duplicate-key error onto the
            // port's own type, which is the obligation DatabasePort states and
            // tooling/contract/DatabasePortContract.php holds all three
            // implementations to. It used to raise the bare PDOException,
            // which no portable catch could name.
            self::assertSame('courses', $violation->table);
            self::assertInstanceOf(\PDOException::class, $violation->getPrevious());
        }
    }
}
