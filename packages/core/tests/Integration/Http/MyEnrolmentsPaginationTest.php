<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Access\ListMyEnrolments;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\DatabaseEnrolmentRepository;
use Shondalai\VidyaLMS\Core\Tests\Support\CountingDatabase;

final class MyEnrolmentsPaginationTest extends TestCase
{
    public function testTheSqlPageAndSingleCourseLookupNeverMaterialiseTheWholeHistory(): void
    {
        $fixture = ManagementFixture::create();
        $fixture->env->database->delete('enrolments', ['user_id' => 50]);
        $firstCourseId = 0;
        $firstEnrolmentId = 0;
        for ($i = 0; $i < 1021; $i++) {
            $courseId = $fixture->env->database->insert('courses', [
                'title' => 'Learning course ' . $i, 'slug' => 'learning-course-' . $i, 'created_by' => 1,
                'created_at' => '2026-01-15 00:00:00', 'updated_at' => '2026-01-15 00:00:00',
            ]);
            if ($i === 0) {
                $firstCourseId = $courseId;
            }
            $enrolmentId = $fixture->env->database->insert('enrolments', [
                'user_id' => $i < 1001 ? 50 : 10, 'course_id' => $courseId, 'entitlement_id' => $i + 1,
                'enrolled_at' => '2026-01-15 00:00:00', 'status' => 'active', 'progress_percent' => $i % 100,
                'created_at' => '2026-01-15 00:00:00', 'updated_at' => '2026-01-15 00:00:00',
            ]);
            if ($i === 0) {
                $firstEnrolmentId = $enrolmentId;
            }
        }
        $database = new CountingDatabase($fixture->env->database);
        $list = new ListMyEnrolments(
            new AccessGuard($fixture->env->authorization),
            new DatabaseEnrolmentRepository($database, $fixture->env->clock)
        );
        $page = $list->page($fixture->learner(), 2, 20);
        self::assertSame(1001, $page['total']);
        self::assertSame(2, $page['page']);
        self::assertSame(20, $page['per_page']);
        self::assertCount(20, $page['items']);
        self::assertSame($firstEnrolmentId + 980, $page['items'][0]['id']);
        self::assertSame(20, $database->rowsRead);
        // Page, one batched access-source lookup, and total; never one read per enrolment.
        self::assertSame(3, $database->reads);
        self::assertSame([50], array_values(array_unique(array_column($page['items'], 'user_id'))));

        $database->reset();
        $oldest = $list->forCourse($fixture->learner(), $firstCourseId);
        self::assertIsArray($oldest);
        self::assertSame($firstEnrolmentId, $oldest['id']);
        self::assertSame('Learning course 0', $oldest['course_title']);
        self::assertSame(1, $database->rowsRead);
        // One enrolment plus its access sources, without scanning the learner's history.
        self::assertSame(2, $database->reads);
        self::assertNull($list->forCourse($fixture->teacher(), $firstCourseId));

        $response = $fixture->call('GET', '/me/enrolments', $fixture->learner(), query: [
            'page' => '51', 'per_page' => '20', 'user_id' => '10',
        ]);
        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertSame(1001, $response->data['total']);
        self::assertCount(1, $response->data['items']);
        self::assertSame($firstEnrolmentId, $response->data['items'][0]['id']);
        self::assertSame('private, no-store', $response->headers['Cache-Control']);
        $coursePage = $fixture->call(
            'GET',
            '/me/enrolments',
            $fixture->learner(),
            query: ['course_id' => (string) $firstCourseId]
        );
        self::assertIsArray($coursePage->data);
        self::assertSame(1, $coursePage->data['total']);
        self::assertCount(1, $coursePage->data['items']);
    }

    public function testHttpDefaultsAndBoundsCannotBroadenThePrincipalScope(): void
    {
        $fixture = ManagementFixture::create();
        $empty = $fixture->call('GET', '/me/enrolments', $fixture->teacher());
        self::assertSame(['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 20], $empty->data);
        foreach ([['page' => '0'], ['page' => '1000001'], ['per_page' => '101'], ['course_id' => '0']] as $query) {
            self::assertSame(422, $fixture->call('GET', '/me/enrolments', $fixture->learner(), query: $query)->status);
        }
        self::assertSame(401, $fixture->call('GET', '/me/enrolments', Principal::guest(), query: ['user_id' => '50'])->status);
        $this->expectException(NotAuthorizedException::class);
        $fixture->kernel->listMyEnrolments->forCourse(Principal::guest(), $fixture->courseId);
    }
}
