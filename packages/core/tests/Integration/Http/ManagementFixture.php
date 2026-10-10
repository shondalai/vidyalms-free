<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * A site with one course fully built through the management surface, so the
 * route tests exercise the same path an admin screen would.
 *
 * Two staff and two courses on purpose: the teacher is an instructor of the
 * first course and a stranger to the second, which is what the per-course
 * rule turns on.
 */
final class ManagementFixture
{
    public const TEACHER_ID = 10;
    public const OUTSIDER_ID = 11;
    public const MANAGER_ID = 1;
    public const LEARNER_ID = 50;

    public readonly ApiKernel $api;

    public int $courseId = 0;
    public int $otherCourseId = 0;
    public int $moduleId = 0;
    public int $lessonId = 0;
    public int $quizLessonId = 0;
    public int $assignmentLessonId = 0;
    public int $quizId = 0;
    public int $questionId = 0;
    public int $entitlementId = 0;

    private function __construct(
        public readonly TestPorts $env,
        public readonly CoreKernel $kernel,
    ) {
        $this->api = $kernel->apiKernel();
    }

    public static function create(): self
    {
        $env = TestPorts::create();

        foreach ([Capability::TEACH, Capability::GRADE, Capability::ENROL_OTHERS, Capability::VIEW_REPORTS] as $capability) {
            $env->authorization->grant(self::TEACHER_ID, $capability);
            $env->authorization->grant(self::OUTSIDER_ID, $capability);
        }

        $env->authorization->grant(self::MANAGER_ID, Capability::MANAGE);
        $env->authorization->grant(self::MANAGER_ID, Capability::VIEW_REPORTS);

        $env->users->add(self::TEACHER_ID, 'Tara Teacher', 'tara@example.test');
        $env->users->add(self::OUTSIDER_ID, 'Owen Outsider', 'owen@example.test');
        $env->users->add(self::MANAGER_ID, 'Mo Manager', 'mo@example.test');
        $env->users->add(self::LEARNER_ID, 'Lena Learner', 'lena@example.test');

        $kernel = new CoreKernel($env->ports, [
            'verification_base_url' => 'https://example.test/verify',
            'expiry_sweep_schedule' => 'hourly',
            'host_settings_url' => 'https://example.test/administrator',
            'self_enrolment' => true,
            // What an adapter derives from the site's own from address, so
            // the envelope sender the queue composes is a real address here
            // as it is on a site.
            'envelope_domain' => 'example.test',
        ]);

        $fixture = new self($env, $kernel);
        $fixture->seed();

        return $fixture;
    }

    public function teacher(): Principal
    {
        return Principal::user(self::TEACHER_ID);
    }

    /** A teacher of another course entirely; every course-scoped route refuses them. */
    public function outsider(): Principal
    {
        return Principal::user(self::OUTSIDER_ID);
    }

    public function manager(): Principal
    {
        return Principal::user(self::MANAGER_ID);
    }

    public function learner(): Principal
    {
        return Principal::user(self::LEARNER_ID);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public function call(
        string $method,
        string $path,
        Principal $principal,
        array $body = [],
        array $query = [],
        ?string $idempotencyKey = null,
        array $headers = [],
    ): ApiResponse {
        return $this->api->handle(new ApiRequest($method, $path, $principal, $query, $body, $idempotencyKey, headers: array_change_key_case($headers)));
    }

    /**
     * Every {placeholder} of a route pattern replaced with a real id from
     * this fixture, so a table of routes reads as the contract writes them.
     */
    public function path(string $pattern): string
    {
        return strtr($pattern, [
            '{course}' => (string) $this->courseId,
            '{other_course}' => (string) $this->otherCourseId,
            '{module}' => (string) $this->moduleId,
            '{lesson}' => (string) $this->lessonId,
            '{assignment_lesson}' => (string) $this->assignmentLessonId,
            '{quiz}' => (string) $this->quizId,
            '{question}' => (string) $this->questionId,
            '{entitlement}' => (string) $this->entitlementId,
            '{learner}' => (string) self::LEARNER_ID,
        ]);
    }

    private function seed(): void
    {
        $teacher = $this->teacher();

        $this->courseId = $this->created('POST', '/manage/courses', $teacher, ['title' => 'Tea Tasting']);
        $this->otherCourseId = $this->created('POST', '/manage/courses', $this->outsider(), ['title' => 'Coffee Roasting']);

        $this->moduleId = $this->created(
            'POST',
            '/manage/courses/' . $this->courseId . '/modules',
            $teacher,
            ['title' => 'Basics'],
        );

        $this->lessonId = $this->created('POST', '/manage/modules/' . $this->moduleId . '/lessons', $teacher, [
            'title' => 'Welcome',
            'type' => 'reading',
            'content' => ['body' => '<p>Steep well.</p>'],
        ]);

        $quizLesson = $this->call('POST', '/manage/modules/' . $this->moduleId . '/lessons', $teacher, [
            'title' => 'Checkpoint',
            'type' => 'quiz',
        ]);
        $this->quizLessonId = (int) ($quizLesson->data['id'] ?? 0);
        $this->quizId = (int) ($quizLesson->data['quiz']['id'] ?? 0);

        $this->assignmentLessonId = $this->created('POST', '/manage/modules/' . $this->moduleId . '/lessons', $teacher, [
            'title' => 'Tasting notes',
            'type' => 'assignment',
        ]);

        $this->questionId = $this->created('POST', '/manage/quizzes/' . $this->quizId . '/questions', $teacher, [
            'type' => 'short_answer',
            'definition' => ['prompt' => 'Describe an oolong.', 'accepted' => []],
            'points' => 5,
        ]);

        $this->call('POST', '/manage/courses/' . $this->courseId . '/publish', $teacher);

        $grant = $this->call('POST', '/manage/courses/' . $this->courseId . '/learners', $teacher, [
            'user_id' => self::LEARNER_ID,
        ]);
        $this->entitlementId = (int) ($grant->data['entitlement']['id'] ?? 0);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function created(string $method, string $path, Principal $principal, array $body): int
    {
        $response = $this->call($method, $path, $principal, $body);

        if ($response->status !== 201 && $response->status !== 200) {
            throw new \RuntimeException(sprintf(
                'Fixture call %s %s answered %d: %s',
                $method,
                $path,
                $response->status,
                json_encode($response->data, JSON_THROW_ON_ERROR),
            ));
        }

        return (int) ($response->data['id'] ?? 0);
    }
}
