<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class CourseVisibilitySecurityTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->database->update('courses', ['visibility' => 'private'], ['id' => $this->fixture->courseId]);
        $this->fixture->env->database->update('lessons', ['free_preview' => 1], ['id' => $this->fixture->lessonId]);
    }

    /** @return array<string, array{int, string|null, int}> */
    public static function privateReaders(): array
    {
        return [
            'guest' => [0, null, 404],
            'unrelated account' => [77, null, 404],
            'other course instructor' => [ManagementFixture::OUTSIDER_ID, null, 404],
            'assigned instructor' => [ManagementFixture::TEACHER_ID, null, 200],
            'site manager' => [ManagementFixture::MANAGER_ID, null, 200],
            'active learner' => [ManagementFixture::LEARNER_ID, 'active', 200],
            'completed learner' => [ManagementFixture::LEARNER_ID, 'completed', 200],
            'suspended learner' => [ManagementFixture::LEARNER_ID, 'suspended', 404],
            'cancelled learner' => [ManagementFixture::LEARNER_ID, 'cancelled', 404],
        ];
    }

    #[DataProvider('privateReaders')]
    public function testPrivateCourseOutlineAndPreviewShareTheAccessBoundary(int $userId, ?string $status, int $expected): void
    {
        if ($status !== null) {
            $this->fixture->env->database->update('enrolments', ['status' => $status], [
                'course_id' => $this->fixture->courseId,
                'user_id' => $userId,
            ]);
        }
        $principal = $userId === 0 ? Principal::guest() : Principal::user($userId);

        foreach ($this->readRoutes() as $route) {
            $response = $this->fixture->call('GET', $route, $principal);
            self::assertSame($expected, $response->status, $route . ': ' . json_encode($response->data));
            if ($expected === 404) {
                self::assertStringNotContainsString('Tea Tasting', (string) json_encode($response->data));
                self::assertArrayNotHasKey('modules', $response->data ?? []);
                self::assertArrayNotHasKey('content', $response->data ?? []);
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function directVisibilities(): array
    {
        return ['public' => ['public'], 'unlisted' => ['unlisted']];
    }

    #[DataProvider('directVisibilities')]
    public function testPublicAndUnlistedRetainDirectReadsAndSelfEnrolment(string $visibility): void
    {
        $this->fixture->env->database->update('courses', ['visibility' => $visibility], ['id' => $this->fixture->courseId]);
        foreach ([Principal::guest(), Principal::user(77)] as $principal) {
            foreach ($this->readRoutes() as $route) {
                self::assertSame(200, $this->fixture->call('GET', $route, $principal)->status, $route);
            }
        }
        self::assertTrue($this->fixture->kernel->selfEnrol->isAvailableFor(Principal::user(77), $this->fixture->courseId));
        self::assertSame(201, $this->enrolAs(Principal::user(77)));
        self::assertSame(401, $this->enrolAs(Principal::guest()));
    }

    public function testUnlistedAndPrivateStayOutOfThePublicCatalogue(): void
    {
        foreach (['private', 'unlisted'] as $visibility) {
            $this->fixture->env->database->update('courses', ['visibility' => $visibility], ['id' => $this->fixture->courseId]);
            $response = $this->fixture->call('GET', '/courses', Principal::guest());
            self::assertSame(200, $response->status);
            self::assertSame([], $response->data['items'] ?? null);
        }
    }

    /** @return array<string, array{string}> */
    public static function nonPublicStates(): array
    {
        return ['draft' => ['draft'], 'archived' => ['archived'], 'trashed' => ['trashed']];
    }

    #[DataProvider('nonPublicStates')]
    public function testEnrolmentDoesNotExposeUnpublishedPrivateCourses(string $state): void
    {
        $this->fixture->env->database->update('courses', ['state' => $state], ['id' => $this->fixture->courseId]);
        foreach ($this->readRoutes() as $route) {
            self::assertSame(404, $this->fixture->call('GET', $route, $this->fixture->learner())->status, $route);
        }
    }

    public function testNewPrivateSelfEnrolmentIsRefusedWithoutSideEffects(): void
    {
        $database = $this->fixture->env->database;
        $entitlements = $database->selectAll(Query::from('entitlements'));
        $enrolments = $database->selectAll(Query::from('enrolments'));
        $events = $this->fixture->env->eventBridge->published;

        foreach ([Principal::user(77), $this->fixture->outsider(), $this->fixture->teacher(), $this->fixture->manager()] as $principal) {
            self::assertFalse($this->fixture->kernel->selfEnrol->isAvailableFor($principal, $this->fixture->courseId));
            self::assertSame(409, $this->enrolAs($principal));
            try {
                $this->fixture->kernel->selfEnrol->execute($principal, $this->fixture->courseId);
                self::fail('The core use case must refuse private self-enrolment too.');
            } catch (ConflictException) {
                self::assertSame($enrolments, $database->selectAll(Query::from('enrolments')));
            }
        }
        self::assertSame(401, $this->enrolAs(Principal::guest()));
        self::assertSame($entitlements, $database->selectAll(Query::from('entitlements')));
        self::assertSame($events, $this->fixture->env->eventBridge->published);
    }

    /** @return array<string, array{string, int}> */
    public static function existingStatuses(): array
    {
        return ['active' => ['active', 201], 'completed' => ['completed', 201],
            'suspended' => ['suspended', 409], 'cancelled' => ['cancelled', 409]];
    }

    #[DataProvider('existingStatuses')]
    public function testPrivateSelfEnrolmentNeverCreatesOrReactivatesAccess(string $status, int $expected): void
    {
        $database = $this->fixture->env->database;
        $database->update('enrolments', ['status' => $status], ['course_id' => $this->fixture->courseId]);
        $enrolments = $database->selectAll(Query::from('enrolments'));
        $entitlements = $database->selectAll(Query::from('entitlements'));
        $events = $this->fixture->env->eventBridge->published;

        self::assertFalse($this->fixture->kernel->selfEnrol->isAvailableFor($this->fixture->learner(), $this->fixture->courseId));
        self::assertSame($expected, $this->enrolAs($this->fixture->learner()));
        self::assertSame($enrolments, $database->selectAll(Query::from('enrolments')));
        self::assertSame($entitlements, $database->selectAll(Query::from('entitlements')));
        self::assertSame($events, $this->fixture->env->eventBridge->published);
    }

    public function testInstructorsCanStillManuallyEnrolPrivateCourseLearners(): void
    {
        $this->fixture->env->users->add(77);
        $response = $this->fixture->call(
            'POST',
            '/manage/courses/' . $this->fixture->courseId . '/learners',
            $this->fixture->teacher(),
            ['user_id' => 77]
        );
        self::assertSame(201, $response->status, (string) json_encode($response->data));
        foreach ($this->readRoutes() as $route) {
            self::assertSame(200, $this->fixture->call('GET', $route, Principal::user(77))->status, $route);
        }
    }

    /** @return list<string> */
    private function readRoutes(): array
    {
        return ['/courses/tea-tasting', '/courses/' . $this->fixture->courseId . '/curriculum', '/lessons/' . $this->fixture->lessonId];
    }

    private function enrolAs(Principal $principal): int
    {
        return $this->fixture->call(
            'POST',
            '/enrolments',
            $principal,
            ['course_id' => $this->fixture->courseId],
            [],
            'visibility-' . $principal->userId
        )->status;
    }
}
