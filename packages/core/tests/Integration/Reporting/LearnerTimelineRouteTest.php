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
 * The learner timeline route through the real routed kernel.
 *
 * Routed rather than a direct call on the use case, because two of the four
 * properties this route promises are properties of the routing and not of the
 * class: that `/manage/learners/{user_id}/timeline` is not swallowed by the
 * access-evidence path beside it, and that the cursor and the limit arrive as
 * the strings a query string is always made of.
 */
final class LearnerTimelineRouteTest extends TestCase
{
    private const MANAGER = 1;
    private const LEARNER = 50;
    private const STRANGER = 51;

    private TestPorts $env;
    private ApiKernel $api;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::MANAGER, Capability::MANAGE);
        $this->env->authorization->grant(self::MANAGER, Capability::VIEW_REPORTS);
        $this->env->users->add(self::MANAGER, 'Mo Manager', 'mo@example.test');
        $this->env->users->add(self::LEARNER, 'Lena Learner', 'lena@example.test');
        $this->env->users->add(self::STRANGER, 'Sam Stranger', 'sam@example.test');

        $kernel = new CoreKernel($this->env->ports, [
            'verification_base_url' => 'https://example.test/verify',
        ]);
        $this->api = $kernel->apiKernel();
    }

    public function testTheRouteAnswersTheActivityStreamsShapeNewestFirst(): void
    {
        $this->activity(self::LEARNER, '2026-03-01 09:00:00', 7, 11);
        $this->activity(self::LEARNER, '2026-03-02 09:00:00', 7, 12);

        $response = $this->call('GET', '/manage/learners/50/timeline', $this->manager());

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertSame(2, $response->data['totalItems']);
        self::assertNull($response->data['next']);

        $items = $response->data['orderedItems'];
        self::assertCount(2, $items);
        self::assertSame('lesson.viewed', $items[0]['type']);
        self::assertSame(['type' => 'user', 'id' => self::LEARNER], $items[0]['actor']);
        self::assertSame(['type' => 'course', 'id' => 7], $items[0]['target']);
        self::assertSame(['type' => 'lesson', 'id' => 12], $items[0]['object']);
        // Newest first: the second row is the one the reader wants at the top.
        self::assertStringStartsWith('2026-03-02', (string) $items[0]['published']);
    }

    /**
     * The cursor arrives from a query string, which is always strings, and the
     * page it resumes must not repeat or skip the boundary row.
     */
    public function testTheKeysetCursorPagesWithoutRepeatingOrSkippingARow(): void
    {
        for ($day = 1; $day <= 5; $day++) {
            $this->activity(self::LEARNER, sprintf('2026-03-%02d 09:00:00', $day), 7, 100 + $day);
        }

        $first = $this->call('GET', '/manage/learners/50/timeline', $this->manager(), ['limit' => '2']);

        self::assertIsArray($first->data);
        self::assertCount(2, $first->data['orderedItems']);
        self::assertIsString($first->data['next']);

        $second = $this->call('GET', '/manage/learners/50/timeline', $this->manager(), [
            'limit' => '2',
            'cursor' => $first->data['next'],
        ]);

        self::assertIsArray($second->data);
        self::assertCount(2, $second->data['orderedItems']);

        $seen = array_map(
            static fn (array $item): int => (int) $item['id'],
            [...$first->data['orderedItems'], ...$second->data['orderedItems']],
        );
        self::assertSame($seen, array_values(array_unique($seen)));
        self::assertCount(4, $seen);
    }

    public function testTheCourseFilterNarrowsToOneCourse(): void
    {
        $this->activity(self::LEARNER, '2026-03-01 09:00:00', 7, 11);
        $this->activity(self::LEARNER, '2026-03-02 09:00:00', 8, 12);

        $response = $this->call('GET', '/manage/learners/50/timeline', $this->manager(), ['course_id' => '8']);

        self::assertIsArray($response->data);
        self::assertSame(1, $response->data['totalItems']);
        self::assertSame(['type' => 'course', 'id' => 8], $response->data['orderedItems'][0]['target']);
    }

    public function testALearnerReadsTheirOwnTimelineWithNoReportsCapability(): void
    {
        $this->activity(self::LEARNER, '2026-03-01 09:00:00', 7, 11);

        $response = $this->call('GET', '/manage/learners/50/timeline', $this->learner());

        self::assertSame(200, $response->status);
        self::assertIsArray($response->data);
        self::assertSame(1, $response->data['totalItems']);
    }

    /**
     * The largest personal-data read in the product, so somebody else's rows
     * are behind the reports capability rather than behind having an account.
     */
    public function testAStrangerCannotReadSomebodyElsesTimeline(): void
    {
        $this->activity(self::LEARNER, '2026-03-01 09:00:00', 7, 11);

        $response = $this->call('GET', '/manage/learners/50/timeline', $this->stranger());

        self::assertSame(403, $response->status);
    }

    public function testAGuestIsRefused(): void
    {
        self::assertSame(401, $this->call('GET', '/manage/learners/50/timeline', Principal::guest())->status);
    }

    /**
     * The access-evidence route lives one segment deeper on the same prefix.
     * Registration is first match wins with no literal-over-parameter
     * precedence, so this asserts the two do not shadow each other.
     */
    public function testTheEvidenceRouteBesideItStillReachesItsOwnHandler(): void
    {
        $response = $this->call(
            'GET',
            '/manage/learners/50/courses/7/access-evidence',
            $this->manager(),
        );

        // Its own refusal, which names the course, rather than the kernel's
        // bare route miss, which carries no detail at all. That is what tells
        // the two apart: a swallowed path answers a detail-less 404.
        self::assertSame(404, $response->status);
        self::assertIsArray($response->data);
        self::assertArrayNotHasKey('orderedItems', $response->data);
        self::assertStringContainsString('Course', (string) ($response->data['detail'] ?? ''));

        $unrouted = $this->call('GET', '/manage/learners/50/nothing-here', $this->manager());
        self::assertSame(404, $unrouted->status);
        self::assertIsArray($unrouted->data);
        self::assertArrayNotHasKey('detail', $unrouted->data);
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

    private function manager(): Principal
    {
        return Principal::user(self::MANAGER);
    }

    private function learner(): Principal
    {
        return Principal::user(self::LEARNER);
    }

    private function stranger(): Principal
    {
        return Principal::user(self::STRANGER);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function call(string $method, string $path, Principal $principal, array $query = []): ApiResponse
    {
        return $this->api->handle(new ApiRequest($method, $path, $principal, $query));
    }
}
