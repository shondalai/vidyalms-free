<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

final class MyCertificatesPaginationTest extends TestCase
{
    public function testAThousandCertificatesArePagedInTheDatabaseAndFilteredByCurrentOwnerAndCourse(): void
    {
        $fixture = ManagementFixture::create();
        for ($i = 0; $i < 1021; $i++) {
            $fixture->env->database->insert('certificates', [
                'user_id' => $i < 1001 ? 50 : 10, 'course_id' => $i % 2 === 0 ? $fixture->courseId : $fixture->otherCourseId,
                'enrolment_id' => $i + 1, 'template_id' => 1, 'code' => 'P' . str_pad((string) $i, 11, '0', STR_PAD_LEFT),
                'issued_at' => '2026-01-15 00:00:00', 'status' => 'superseded',
                'created_at' => '2026-01-15 00:00:00', 'updated_at' => '2026-01-15 00:00:00',
            ]);
        }
        $result = $fixture->call(
            'GET',
            '/me/certificates',
            $fixture->learner(),
            query: ['page' => '2', 'per_page' => '100', 'user_id' => '10']
        );
        self::assertSame(200, $result->status);
        self::assertIsArray($result->data);
        self::assertSame(1001, $result->data['total']);
        self::assertSame(2, $result->data['page']);
        self::assertSame(100, $result->data['per_page']);
        self::assertCount(100, $result->data['items']);
        self::assertSame(901, $result->data['items'][0]['id']);
        self::assertSame('private, no-store', $result->headers['Cache-Control']);
        $course = $fixture->call('GET', '/me/certificates', $fixture->learner(), query: [
            'page' => '6', 'per_page' => '100', 'course_id' => (string) $fixture->courseId]);
        self::assertIsArray($course->data);
        self::assertSame(501, $course->data['total']);
        self::assertCount(1, $course->data['items']);
        self::assertSame(1, $course->data['items'][0]['id']);
        $empty = $fixture->call('GET', '/me/certificates', $fixture->learner(), query: ['course_id' => '99999']);
        self::assertIsArray($empty->data);
        self::assertSame(0, $empty->data['total']);
        self::assertSame([], $empty->data['items']);
    }

    public function testDefaultsBoundsAndGuestAuthority(): void
    {
        $fixture = ManagementFixture::create();
        $empty = $fixture->call('GET', '/me/certificates', $fixture->learner());
        self::assertSame(['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 25], $empty->data);
        $invalidQueries = [
            ['page' => '0'], ['page' => '1000001'], ['per_page' => '101'],
            ['course_id' => '0'], ['course_id' => 'invalid'],
        ];
        foreach ($invalidQueries as $query) {
            self::assertSame(422, $fixture->call('GET', '/me/certificates', $fixture->learner(), query: $query)->status);
        }
        self::assertSame(401, $fixture->call('GET', '/me/certificates', Principal::guest(), query: ['user_id' => '50'])->status);
    }
}
