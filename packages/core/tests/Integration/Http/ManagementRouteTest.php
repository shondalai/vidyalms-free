<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;

/**
 * Every route of the management surface, through the ApiKernel: the answer a
 * guest gets, the answer a teacher who is not an instructor of the course
 * gets, and, where a body exists, the answer an invalid one gets.
 *
 * The tables below are the route list. A route added to the kernel without a
 * row here is a route nobody proved refuses a guest.
 */
final class ManagementRouteTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function routes(): array
    {
        return [
            'GET /lesson-types' => ['GET', '/lesson-types', []],
            'GET /question-types' => ['GET', '/question-types', []],
            'GET /manage/course-categories' => ['GET', '/manage/course-categories', []],
            'GET /manage/course-categories/{id}' => ['GET', '/manage/course-categories/1', []],
            'GET /manage/courses' => ['GET', '/manage/courses', []],
            'POST /manage/courses' => ['POST', '/manage/courses', ['title' => 'New']],
            'GET /manage/courses/{id}' => ['GET', '/manage/courses/{course}', []],
            'PATCH /manage/courses/{id}' => ['PATCH', '/manage/courses/{course}', ['title' => 'Renamed']],
            'POST /manage/courses/{id}/publish' => ['POST', '/manage/courses/{course}/publish', []],
            'POST /manage/courses/{id}/archive' => ['POST', '/manage/courses/{course}/archive', []],
            'PUT /manage/courses/{id}/prerequisites' => ['PUT', '/manage/courses/{course}/prerequisites', ['course_ids' => []]],
            'GET /manage/courses/{id}/instructors' => ['GET', '/manage/courses/{course}/instructors', []],
            'POST /manage/courses/{id}/instructors' => ['POST', '/manage/courses/{course}/instructors', ['user_id' => 1]],
            'DELETE /manage/courses/{id}/instructors/{user_id}' => [
                'DELETE',
                '/manage/courses/{course}/instructors/{learner}',
                [],
            ],
            'GET /manage/courses/{id}/curriculum' => ['GET', '/manage/courses/{course}/curriculum', []],
            'POST /manage/courses/{id}/modules' => ['POST', '/manage/courses/{course}/modules', ['title' => 'Another']],
            'PUT /manage/courses/{id}/modules/order' => [
                'PUT',
                '/manage/courses/{course}/modules/order',
                ['module_ids' => [1]],
            ],
            'PATCH /manage/modules/{id}' => ['PATCH', '/manage/modules/{module}', ['title' => 'Renamed']],
            'DELETE /manage/modules/{id}' => ['DELETE', '/manage/modules/{module}', []],
            'POST /manage/modules/{id}/lessons' => [
                'POST',
                '/manage/modules/{module}/lessons',
                ['title' => 'Another', 'type' => 'reading', 'content' => ['body' => '<p>x</p>']],
            ],
            'PUT /manage/modules/{id}/lessons/order' => [
                'PUT',
                '/manage/modules/{module}/lessons/order',
                ['lesson_ids' => [1]],
            ],
            'GET /manage/lessons/{id}' => ['GET', '/manage/lessons/{lesson}', []],
            'PATCH /manage/lessons/{id}' => ['PATCH', '/manage/lessons/{lesson}', ['title' => 'Renamed']],
            'DELETE /manage/lessons/{id}' => ['DELETE', '/manage/lessons/{lesson}', []],
            'POST /manage/lessons/{id}/move' => ['POST', '/manage/lessons/{lesson}/move', ['module_id' => 1]],
            'PUT /manage/lessons/{id}/assignment' => [
                'PUT',
                '/manage/lessons/{assignment_lesson}/assignment',
                ['max_points' => 10],
            ],
            'GET /manage/courses/{id}/quizzes' => ['GET', '/manage/courses/{course}/quizzes', []],
            'POST /manage/courses/{id}/quizzes' => ['POST', '/manage/courses/{course}/quizzes', ['title' => 'Extra quiz']],
            'GET /manage/quizzes/{id}' => ['GET', '/manage/quizzes/{quiz}', []],
            'PUT /manage/quizzes/{id}' => ['PUT', '/manage/quizzes/{quiz}', ['title' => 'Checkpoint']],
            'POST /manage/quizzes/{id}/questions' => [
                'POST',
                '/manage/quizzes/{quiz}/questions',
                ['type' => 'short_answer', 'definition' => ['prompt' => 'Why?', 'accepted' => []]],
            ],
            'PUT /manage/quizzes/{id}/questions/order' => [
                'PUT',
                '/manage/quizzes/{quiz}/questions/order',
                ['question_ids' => [1]],
            ],
            'PUT /manage/questions/{id}' => [
                'PUT',
                '/manage/questions/{question}',
                ['definition' => ['prompt' => 'Why?', 'accepted' => []], 'points' => 2],
            ],
            'DELETE /manage/questions/{id}' => ['DELETE', '/manage/questions/{question}', []],
            'GET /manage/quizzes/{id}/settings-schema' => ['GET', '/manage/quizzes/{quiz}/settings-schema', []],
            'POST /manage/quizzes/{id}/regrade' => [
                'POST',
                '/manage/quizzes/{quiz}/regrade',
                ['policy' => 'full_credit', 'dry_run' => true],
            ],
            'GET /manage/courses/{id}/stimuli' => ['GET', '/manage/courses/{course}/stimuli', []],
            'POST /manage/courses/{id}/stimuli' => [
                'POST',
                '/manage/courses/{course}/stimuli',
                ['title' => 'A passage'],
            ],
            'GET /manage/courses/{id}/reports/item-analysis' => [
                'GET',
                '/manage/courses/{course}/reports/item-analysis',
                [],
            ],
            'GET /manage/courses/{id}/gradebook' => ['GET', '/manage/courses/{course}/gradebook', []],
            'GET /manage/courses/{id}/learners' => ['GET', '/manage/courses/{course}/learners', []],
            'POST /manage/courses/{id}/learners' => ['POST', '/manage/courses/{course}/learners', ['user_id' => 50]],
            'GET /manage/courses/{id}/learners/{user_id}' => ['GET', '/manage/courses/{course}/learners/{learner}', []],
            'POST /manage/entitlements/{id}/revoke' => ['POST', '/manage/entitlements/{entitlement}/revoke', []],
            'POST /manage/entitlements/{id}/extend' => [
                'POST',
                '/manage/entitlements/{entitlement}/extend',
                ['expires_at' => null],
            ],
            'GET /admin/dashboard' => ['GET', '/admin/dashboard', []],
            'GET /admin/enrolments' => ['GET', '/admin/enrolments', []],
            'GET /admin/learners' => ['GET', '/admin/learners', []],
            'GET /admin/learners/{user_id}' => ['GET', '/admin/learners/{learner}', []],
            'GET /admin/certificates' => ['GET', '/admin/certificates', []],
            'GET /admin/settings' => ['GET', '/admin/settings', []],
            'GET /admin/users' => ['GET', '/admin/users', []],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('routes')]
    public function testGuestIsRefusedEverywhere(string $method, string $pattern, array $body): void
    {
        $response = $this->fixture->call(
            $method,
            $this->fixture->path($pattern),
            Principal::guest(),
            $body,
            ['q' => 'lena'],
        );

        self::assertSame(401, $response->status, $method . ' ' . $pattern);
        self::assertProblem($response);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function courseScopedRoutes(): array
    {
        $siteWide = [
            'GET /lesson-types',
            'GET /question-types',
            'GET /manage/courses',
            'GET /manage/course-categories',
            'GET /manage/course-categories/{id}',
            'POST /manage/courses',
            'GET /admin/dashboard',
            'GET /admin/enrolments',
            'GET /admin/learners',
            'GET /admin/learners/{user_id}',
            'GET /admin/certificates',
            'GET /admin/settings',
            'GET /admin/users',
            // Instructorship is handed out by a manager, so these two answer
            // 403 for a non-manager whether or not they teach the course.
            'POST /manage/courses/{id}/instructors',
            'DELETE /manage/courses/{id}/instructors/{user_id}',
        ];

        return array_diff_key(self::routes(), array_flip($siteWide));
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('courseScopedRoutes')]
    public function testTeacherOfAnotherCourseIsForbidden(string $method, string $pattern, array $body): void
    {
        $response = $this->fixture->call(
            $method,
            $this->fixture->path($pattern),
            $this->fixture->outsider(),
            $body,
        );

        self::assertSame(403, $response->status, $method . ' ' . $pattern);
        self::assertProblem($response);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function bodiedRoutes(): array
    {
        return [
            'POST /manage/courses' => ['POST', '/manage/courses', ['title' => '']],
            'PATCH /manage/courses/{id}' => ['PATCH', '/manage/courses/{course}', ['state' => 'published']],
            'PUT /manage/courses/{id}/prerequisites' => [
                'PUT',
                '/manage/courses/{course}/prerequisites',
                ['course_ids' => ['nope']],
            ],
            'POST /manage/courses/{id}/instructors' => ['POST', '/manage/courses/{course}/instructors', []],
            'POST /manage/courses/{id}/modules' => ['POST', '/manage/courses/{course}/modules', []],
            'PUT /manage/courses/{id}/modules/order' => ['PUT', '/manage/courses/{course}/modules/order', []],
            'PATCH /manage/modules/{id}' => ['PATCH', '/manage/modules/{module}', ['position' => 3]],
            'POST /manage/modules/{id}/lessons' => ['POST', '/manage/modules/{module}/lessons', ['title' => 'x']],
            'PUT /manage/modules/{id}/lessons/order' => ['PUT', '/manage/modules/{module}/lessons/order', []],
            'PATCH /manage/lessons/{id}' => ['PATCH', '/manage/lessons/{lesson}', ['module_id' => 2]],
            'POST /manage/lessons/{id}/move' => ['POST', '/manage/lessons/{lesson}/move', []],
            'PUT /manage/lessons/{id}/assignment' => [
                'PUT',
                '/manage/lessons/{assignment_lesson}/assignment',
                ['max_points' => 0],
            ],
            'POST /manage/courses/{id}/quizzes' => ['POST', '/manage/courses/{course}/quizzes', []],
            'PUT /manage/quizzes/{id}' => ['PUT', '/manage/quizzes/{quiz}', ['title' => 'Q', 'pass_mark' => 400]],
            'POST /manage/quizzes/{id}/questions' => ['POST', '/manage/quizzes/{quiz}/questions', ['type' => 'nope']],
            'PUT /manage/quizzes/{id}/questions/order' => [
                'PUT',
                '/manage/quizzes/{quiz}/questions/order',
                ['question_ids' => []],
            ],
            'PUT /manage/questions/{id}' => ['PUT', '/manage/questions/{question}', ['definition' => []]],
            'POST /manage/courses/{id}/learners' => ['POST', '/manage/courses/{course}/learners', []],
            'POST /manage/entitlements/{id}/extend' => ['POST', '/manage/entitlements/{entitlement}/extend', []],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('bodiedRoutes')]
    public function testInvalidBodiesAnswer422(string $method, string $pattern, array $body): void
    {
        $response = $this->fixture->call(
            $method,
            $this->fixture->path($pattern),
            $pattern === '/manage/courses' ? $this->fixture->teacher() : $this->fixture->manager(),
            $body,
        );

        self::assertSame(422, $response->status, $method . ' ' . $pattern);
        self::assertProblem($response);
        self::assertNotNull($response->data);
        self::assertIsArray($response->data['errors']);
        self::assertNotSame([], $response->data['errors']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, string>}>
     */
    public static function pagedRoutes(): array
    {
        return [
            'GET /manage/course-categories' => ['GET', '/manage/course-categories', ['sort' => 'nonsense']],
            'GET /manage/courses' => ['GET', '/manage/courses', ['sort' => 'nonsense']],
            'GET /manage/courses/{id}/gradebook' => ['GET', '/manage/courses/{course}/gradebook', ['status' => 'nonsense']],
            'GET /manage/courses/{id}/learners' => ['GET', '/manage/courses/{course}/learners', ['sort' => 'nonsense']],
            'GET /admin/enrolments' => ['GET', '/admin/enrolments', ['sort' => 'nonsense']],
            'GET /admin/learners' => ['GET', '/admin/learners', ['sort' => 'nonsense']],
            'GET /admin/certificates' => ['GET', '/admin/certificates', ['sort' => 'nonsense']],
            'GET /admin/users' => ['GET', '/admin/users', ['q' => 'a']],
        ];
    }

    /**
     * @param array<string, string> $query
     */
    #[DataProvider('pagedRoutes')]
    public function testInvalidQueryParametersAnswer422(string $method, string $pattern, array $query): void
    {
        $response = $this->fixture->call(
            $method,
            $this->fixture->path($pattern),
            $this->fixture->manager(),
            [],
            $query,
        );

        self::assertSame(422, $response->status, $method . ' ' . $pattern);
        self::assertProblem($response);
    }

    public function testAReplayedMutationReturnsTheStoredResponseWithoutRunningAgain(): void
    {
        $teacher = $this->fixture->teacher();
        $path = $this->fixture->path('/manage/courses/{course}/modules');

        $first = $this->fixture->call('POST', $path, $teacher, ['title' => 'Replay me'], [], 'key-123');
        $second = $this->fixture->call('POST', $path, $teacher, ['title' => 'Replay me'], [], 'key-123');

        self::assertSame(201, $first->status);
        self::assertSame(201, $second->status);
        self::assertSame('true', $second->headers['Idempotent-Replayed'] ?? null);
        self::assertNotNull($first->data);
        self::assertNotNull($second->data);
        self::assertSame($first->data['id'], $second->data['id']);

        $curriculum = $this->fixture->call('GET', $this->fixture->path('/manage/courses/{course}/curriculum'), $teacher);
        self::assertNotNull($curriculum->data);
        self::assertCount(2, $curriculum->data['modules'], 'The replay wrote nothing.');
    }

    public function testAnUnknownCourseIsNotFoundForACallerWhoMayUseTheSurface(): void
    {
        $response = $this->fixture->call('GET', '/manage/courses/9999', $this->fixture->manager());

        self::assertSame(404, $response->status);
    }

    /**
     * The design pairs the two: a question whose policy is none scores
     * nothing, so it must be authored unscored. The use case has always
     * refused the other pairing; what was missing was the field, so the
     * picker's opinion scale answered a 422 naming "scored" and nothing a
     * client could send changed it.
     */
    public function testAQuestionWhosePolicyScoresNothingIsAuthoredUnscoredThroughTheApi(): void
    {
        $teacher = $this->fixture->teacher();
        $path = $this->fixture->path('/manage/quizzes/{quiz}/questions');
        $scale = [
            'type' => 'single_choice',
            'definition' => [
                'prompt' => 'The pacing suited me.',
                'options' => [['key' => 'agree', 'label' => 'Agree'], ['key' => 'disagree', 'label' => 'Disagree']],
                'policy' => ['key' => 'none'],
            ],
        ];

        $created = $this->fixture->call('POST', $path, $teacher, $scale + ['scored' => false]);
        self::assertSame(201, $created->status);
        self::assertNotNull($created->data);
        self::assertSame('single_choice', $created->data['type']);
        self::assertSame(['key' => 'none'], $created->data['definition']['policy']);

        // Scored, whether said or defaulted, is the pairing the rule refuses,
        // and the problem names the field the client can now set.
        foreach (['stated' => $scale + ['scored' => true], 'defaulted' => $scale] as $case => $body) {
            $refused = $this->fixture->call('POST', $path, $teacher, $body);
            self::assertSame(422, $refused->status, $case);
            self::assertProblem($refused);
            self::assertArrayHasKey('scored', $refused->data['errors'] ?? [], $case);
        }

        // A value that is not a boolean is refused at the door like any other field.
        $malformed = $this->fixture->call('POST', $path, $teacher, $scale + ['scored' => 'no']);
        self::assertSame(422, $malformed->status);
        self::assertArrayHasKey('scored', $malformed->data['errors'] ?? []);
    }

    private static function assertProblem(ApiResponse $response): void
    {
        self::assertSame('application/problem+json', $response->headers['Content-Type'] ?? null);
        self::assertNotNull($response->data);
        self::assertSame($response->status, $response->data['status']);
    }
}
