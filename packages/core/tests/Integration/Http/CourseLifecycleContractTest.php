<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class CourseLifecycleContractTest extends TestCase
{
    public function testTrashRetainsLearningRecordsAndRestoresOnlyAsDraft(): void
    {
        $fixture = ManagementFixture::create();
        $path = $fixture->path('/manage/courses/{course}');
        $before = [];
        foreach (['lessons', 'enrolments', 'entitlements', 'quizzes'] as $table) {
            $before[$table] = $fixture->env->database->count(Query::from($table));
        }

        $response = $fixture->call('DELETE', $path, $fixture->teacher(), idempotencyKey: 'trash-course');
        self::assertSame(204, $response->status);
        self::assertSame(204, $fixture->call('DELETE', $path, $fixture->teacher(), idempotencyKey: 'trash-course')->status);
        self::assertSame(404, $fixture->call('GET', $path, $fixture->teacher())->status);
        self::assertSame(404, $fixture->call('POST', $path . '/publish', $fixture->teacher())->status);
        self::assertSame(404, $fixture->call('GET', $fixture->path('/courses/{course}/curriculum'), $fixture->learner())->status);
        self::assertSame(404, $fixture->call('POST', $fixture->path('/quizzes/{quiz}/attempts'), $fixture->learner())->status);

        $normal = $fixture->call('GET', '/manage/courses', $fixture->teacher());
        self::assertSame(0, $normal->data['total'] ?? null);
        $trash = $fixture->call('GET', '/manage/courses', $fixture->teacher(), query: ['state' => 'trashed']);
        self::assertSame($fixture->courseId, $trash->data['items'][0]['id'] ?? null);

        foreach ($before as $table => $count) {
            self::assertSame($count, $fixture->env->database->count(Query::from($table)), $table);
        }

        self::assertSame(204, $fixture->call('POST', $path . '/restore', $fixture->teacher())->status);
        self::assertSame('draft', $fixture->call('GET', $path, $fixture->teacher())->data['state'] ?? null);
        self::assertSame(200, $fixture->call('POST', $path . '/publish', $fixture->teacher())->status);
    }

    public function testUnpublishingStopsNewAttemptsAndCanBePublishedAgain(): void
    {
        $fixture = ManagementFixture::create();
        $path = $fixture->path('/manage/courses/{course}');
        self::assertSame(204, $fixture->call('POST', $path . '/unpublish', $fixture->teacher())->status);
        self::assertSame('draft', $fixture->call('GET', $path, $fixture->teacher())->data['state'] ?? null);
        self::assertSame(404, $fixture->call('POST', $fixture->path('/quizzes/{quiz}/attempts'), $fixture->learner())->status);
        self::assertSame(200, $fixture->call('POST', $path . '/publish', $fixture->teacher())->status);
    }

    public function testEveryLifecycleActionRequiresTheCourseAuthorPermission(): void
    {
        $fixture = ManagementFixture::create();
        $path = $fixture->path('/manage/courses/{course}');
        foreach ([['DELETE', $path], ['POST', $path . '/unpublish'], ['POST', $path . '/restore']] as [$method, $url]) {
            self::assertSame(401, $fixture->call($method, $url, Principal::guest())->status);
            self::assertSame(403, $fixture->call($method, $url, $fixture->learner())->status);
            self::assertSame(403, $fixture->call($method, $url, $fixture->outsider())->status);
        }
        self::assertSame('published', $fixture->call('GET', $path, $fixture->teacher())->data['state'] ?? null);
    }
}
