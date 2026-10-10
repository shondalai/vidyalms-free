<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\Handler\GetLessonHandler;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Support\ResourcePathCases;

final class ResourceLessonSecurityTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    #[DataProviderExternal(ResourcePathCases::class, 'forbidden')]
    public function testCreateAndUpdateRefuseNonAuthoredPathsWithoutWriting(string $path): void
    {
        $lessonId = $this->createResource('lesson-files/handout.pdf');
        $database = $this->fixture->env->database;
        $before = $database->selectAll(Query::from('lesson_content')->orderBy('lesson_id'));
        $lessonCount = $database->count(Query::from('lessons'));

        foreach (
            [
            ['POST', '/manage/modules/' . $this->fixture->moduleId . '/lessons'],
            ['PATCH', '/manage/lessons/' . $lessonId],
            ] as [$method, $route]
        ) {
            $response = $this->fixture->call($method, $route, $this->fixture->teacher(), [
                'title' => 'Unsafe resource',
                'type' => 'resource',
                'content' => ['files' => [['path' => $path, 'name' => 'Unsafe']]],
            ]);
            self::assertSame(422, $response->status, $method . ' ' . $route);
            self::assertArrayHasKey('files.0.path', $response->data['errors'] ?? []);
        }

        self::assertSame($lessonCount, $database->count(Query::from('lessons')));
        self::assertSame($before, $database->selectAll(Query::from('lesson_content')->orderBy('lesson_id')));
    }

    #[DataProviderExternal(ResourcePathCases::class, 'forbidden')]
    public function testStoredUnsafePathsNeverReachTheSignerForLearnersOrPreviews(string $path): void
    {
        $lessonId = $this->createResource('lesson-files/handout.pdf');
        // Simulate a row saved before validation was enforced, bypassing authoring.
        $this->fixture->env->database->update('lesson_content', [
            'content' => json_encode(StoredShape::wrap(['files' => [
                ['path' => $path, 'name' => 'Unsafe'],
                ['path' => 'lesson-files/handout.pdf', 'name' => 'Allowed'],
            ]], 1), JSON_THROW_ON_ERROR),
        ], ['lesson_id' => $lessonId]);

        $storage = $this->createMock(StoragePort::class);
        $storage->expects(self::exactly(3))->method('signedUrl')
            ->with('lesson-files/handout.pdf', 3600)->willReturn('https://cms.test/safe-download');
        $handler = new GetLessonHandler($this->fixture->kernel->getLessonForLearner, $storage);

        foreach ([Principal::guest(), $this->fixture->learner(), $this->fixture->teacher()] as $principal) {
            $route = '/lessons/' . $lessonId;
            $response = $this->fixture->call('GET', $route, $principal);
            self::assertSame(200, $response->status);
            self::assertSame(['name' => 'Unsafe', 'size_bytes' => null], $response->data['content']['files'][0] ?? null);

            $request = new ApiRequest('GET', $route, $principal);
            $response = $handler->handle($request->withPathParams(['id' => (string) $lessonId]));
            self::assertSame([
                ['name' => 'Unsafe', 'size_bytes' => null],
                ['name' => 'Allowed', 'size_bytes' => null, 'url' => 'https://cms.test/safe-download'],
            ], $response->data['content']['files'] ?? null);
        }
    }

    #[DataProviderExternal(ResourcePathCases::class, 'authored')]
    public function testAuthoredFilesStillReceiveExpiringUrlsWithoutRawPathFields(string $path): void
    {
        $lessonId = $this->createResource($path);

        foreach ([Principal::guest(), $this->fixture->learner()] as $principal) {
            $response = $this->fixture->call('GET', '/lessons/' . $lessonId, $principal);
            self::assertSame(200, $response->status);
            self::assertSame([
                ['name' => 'Handout', 'size_bytes' => null, 'url' => $this->fixture->env->storage->signedUrl($path, 3600)],
            ], $response->data['content']['files'] ?? null);
        }
    }

    private function createResource(string $path): int
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/modules/' . $this->fixture->moduleId . '/lessons',
            $this->fixture->teacher(),
            [
                'title' => 'Handout',
                'type' => 'resource',
                'free_preview' => true,
                'content' => ['files' => [['path' => $path, 'name' => 'Handout']]],
            ],
        );
        self::assertSame(201, $response->status, (string) json_encode($response->data));

        return (int) ($response->data['id'] ?? 0);
    }
}
