<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * `GET /me/timeline`, the learner-facing half of the activity read.
 *
 * The manage route already let a learner read their own rows, so the value of
 * this one is entirely in what it cannot do: there is no `user_id` to bind, so
 * no caller can address anybody else through it. That is asserted here rather
 * than assumed, alongside the two properties that belong to the routing and
 * not to the use case (the personal prefix does not shadow the manage one, and
 * the cursor and limit arrive as the strings a query string is made of).
 */
final class MyTimelineRouteTest extends TestCase
{
    private const MANAGER = 1;
    private const LEARNER = 50;
    private const OTHER = 51;

    private TestPorts $env;
    private ApiKernel $api;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::MANAGER, Capability::MANAGE);
        $this->env->authorization->grant(self::MANAGER, Capability::VIEW_REPORTS);
        $this->env->users->add(self::MANAGER, 'Mo Manager', 'mo@example.test');
        $this->env->users->add(self::LEARNER, 'Lena Learner', 'lena@example.test');
        $this->env->users->add(self::OTHER, 'Otto Other', 'otto@example.test');

        $kernel = new CoreKernel($this->env->ports, [
            'verification_base_url' => 'https://example.test/verify',
        ]);
        $this->api = $kernel->apiKernel();
    }

    public function testTheLearnerReadsTheirOwnRowsWithNoIdInThePath(): void
    {
        $this->activity(self::LEARNER, '2026-03-01 09:00:00', 7, 11);
        $this->activity(self::LEARNER, '2026-03-02 09:00:00', 7, 12);

        $response = $this->call('GET', '/me/timeline', Principal::user(self::LEARNER));

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertSame(2, $response->data['totalItems']);
        self::assertSame(
            ['type' => 'user', 'id' => self::LEARNER],
            $response->data['orderedItems'][0]['actor'],
        );
        self::assertStringStartsWith('2026-03-02', (string) $response->data['orderedItems'][0]['published']);
    }

    /**
     * The property the route exists for: the subject is the principal and
     * there is no parameter that could make it anybody else.
     *
     * Two callers, one route, one set of rows each. A manager with the reports
     * capability sees their own empty timeline here, not the learner's, which
     * is what tells this apart from an alias of the manage route.
     */
    public function testTheSubjectIsAlwaysTheCallerAndNeverAParameter(): void
    {
        $this->activity(self::LEARNER, '2026-03-01 09:00:00', 7, 11);

        $mine = $this->call('GET', '/me/timeline', Principal::user(self::LEARNER));
        $theirs = $this->call('GET', '/me/timeline', Principal::user(self::MANAGER));

        self::assertIsArray($mine->data);
        self::assertIsArray($theirs->data);
        self::assertSame(1, $mine->data['totalItems']);
        self::assertSame(0, $theirs->data['totalItems'], 'A manager read somebody else through /me/timeline.');

        // And a query parameter naming a subject changes nothing, because
        // nothing reads one.
        $attempted = $this->call('GET', '/me/timeline', Principal::user(self::OTHER), ['user_id' => '50']);
        self::assertIsArray($attempted->data);
        self::assertSame(0, $attempted->data['totalItems']);
    }

    public function testTheCourseFilterAndTheKeysetCursorWorkFromTheQueryString(): void
    {
        for ($day = 1; $day <= 4; $day++) {
            $this->activity(self::LEARNER, sprintf('2026-03-%02d 09:00:00', $day), 7, 100 + $day);
        }

        $this->activity(self::LEARNER, '2026-03-09 09:00:00', 8, 200);

        $narrowed = $this->call('GET', '/me/timeline', Principal::user(self::LEARNER), ['course_id' => '8']);
        self::assertIsArray($narrowed->data);
        self::assertSame(1, $narrowed->data['totalItems']);

        $first = $this->call('GET', '/me/timeline', Principal::user(self::LEARNER), ['limit' => '2']);
        self::assertIsArray($first->data);
        self::assertCount(2, $first->data['orderedItems']);
        self::assertIsString($first->data['next']);

        $second = $this->call('GET', '/me/timeline', Principal::user(self::LEARNER), [
            'limit' => '2',
            'cursor' => $first->data['next'],
        ]);
        self::assertIsArray($second->data);

        $seen = array_map(
            static fn (array $item): int => (int) $item['id'],
            [...$first->data['orderedItems'], ...$second->data['orderedItems']],
        );
        self::assertSame($seen, array_values(array_unique($seen)));
        self::assertCount(4, $seen);
    }

    public function testAGuestIsRefused(): void
    {
        self::assertSame(401, $this->call('GET', '/me/timeline', Principal::guest())->status);
    }

    /** The largest personal-data read in the product never sits in a shared cache. */
    public function testTheAnswerIsNotShareable(): void
    {
        $response = $this->call('GET', '/me/timeline', Principal::user(self::LEARNER));

        self::assertSame('private, no-store', $response->headers['Cache-Control'] ?? null);
    }

    private function activity(int $userId, string $occurredAt, ?int $courseId, ?int $lessonId): void
    {
        $this->env->database->insert('activity_log', [
            'user_id' => $userId,
            'course_id' => $courseId,
            'lesson_id' => $lessonId,
            'action' => 'lesson.viewed',
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function call(string $method, string $path, Principal $principal, array $query = []): ApiResponse
    {
        return $this->api->handle(new ApiRequest($method, $path, $principal, $query));
    }
}
