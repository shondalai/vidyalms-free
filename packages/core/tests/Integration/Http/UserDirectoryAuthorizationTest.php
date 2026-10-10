<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\SearchUsers;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;

final class UserDirectoryAuthorizationTest extends TestCase
{
    /** @return array<string, array{int, string|null, string|null, int}> */
    public static function callers(): array
    {
        return [
            'guest' => [0, null, null, 401],
            'learner' => [ManagementFixture::LEARNER_ID, null, null, 403],
            'assigned instructor' => [ManagementFixture::TEACHER_ID, null, null, 403],
            'other instructor' => [ManagementFixture::OUTSIDER_ID, null, null, 403],
            'enroller' => [77, Capability::ENROL_OTHERS, null, 403],
            'course manager' => [77, Capability::MANAGE, 'course', 403],
            'site manager' => [ManagementFixture::MANAGER_ID, null, null, 200],
            'site administrator' => [77, Capability::ADMIN, null, 200],
        ];
    }

    #[DataProvider('callers')]
    public function testGlobalAccountSearchRequiresSiteAdministration(int $userId, ?string $capability, ?string $scope, int $status): void
    {
        $fixture = ManagementFixture::create();
        if ($capability !== null) {
            $fixture->env->authorization->grant($userId, $capability, $scope === null ? null : CourseAccess::resource($fixture->courseId));
        }
        $principal = $userId === 0 ? Principal::guest() : Principal::user($userId);
        foreach (['Le', 'lena', 'lena@example.test', '@example.test'] as $query) {
            $response = $fixture->call('GET', '/admin/users', $principal, [], ['q' => $query, 'course_id' => (string) $fixture->courseId]);
            self::assertSame($status, $response->status, $query);
            if ($status === 200) {
                self::assertContains(ManagementFixture::LEARNER_ID, array_column($response->data['items'] ?? [], 'id'));
            } else {
                self::assertArrayNotHasKey('items', $response->data ?? []);
                self::assertStringNotContainsString('lena@example.test', (string) json_encode($response->data));
            }
        }

        if ($status !== 200) {
            $users = $this->createMock(UsersPort::class);
            $users->expects(self::never())->method('search');
            $users->expects(self::never())->method('matchNamePrefixOrEmail');
            $search = new SearchUsers($users, new AccessGuard($fixture->env->authorization));
            $this->expectException(NotAuthorizedException::class);
            $search->execute($principal, 'Le');
        }
    }

    public function testInstructorRosterRemainsLimitedToTheirCourse(): void
    {
        $fixture = ManagementFixture::create();
        $response = $fixture->call('GET', '/manage/courses/' . $fixture->courseId . '/learners', $fixture->teacher());
        self::assertSame(200, $response->status);
        self::assertCount(1, $response->data['items'] ?? []);
        self::assertSame(403, $fixture->call('GET', '/manage/courses/' . $fixture->courseId . '/learners', $fixture->outsider())->status);
    }
}
