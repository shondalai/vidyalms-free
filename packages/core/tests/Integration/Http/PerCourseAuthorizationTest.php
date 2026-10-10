<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * The per-course rule, which is a security fix as much as a feature.
 *
 * Before it, any group granted vidyalms.teach could edit every course on the
 * site: the capability was checked, the course was not. These tests hold the
 * two halves apart. A teacher who instructs course A and not course B is
 * refused on B and served on A; a manager is served on both; and the reading
 * surface a learner uses is untouched by any of it.
 */
final class PerCourseAuthorizationTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    /**
     * Course B belongs to the outsider. Every one of these is a course-scoped
     * write or read the teacher of course A may not reach.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function courseScopedActions(): array
    {
        return [
            'update course' => ['PATCH', '/manage/courses/{other_course}', ['title' => 'Taken over']],
            'publish course' => ['POST', '/manage/courses/{other_course}/publish', []],
            'archive course' => ['POST', '/manage/courses/{other_course}/archive', []],
            'set prerequisites' => ['PUT', '/manage/courses/{other_course}/prerequisites', ['course_ids' => []]],
            'read the workbench' => ['GET', '/manage/courses/{other_course}', []],
            'read the curriculum' => ['GET', '/manage/courses/{other_course}/curriculum', []],
            'add a module' => ['POST', '/manage/courses/{other_course}/modules', ['title' => 'Mine now']],
            'reorder modules' => ['PUT', '/manage/courses/{other_course}/modules/order', ['module_ids' => []]],
            'update a module' => ['PATCH', '/manage/modules/{other_module}', ['title' => 'Mine now']],
            'add a lesson' => [
                'POST',
                '/manage/modules/{other_module}/lessons',
                ['title' => 'Mine', 'type' => 'reading', 'content' => ['body' => '<p>x</p>']],
            ],
            'reorder lessons' => ['PUT', '/manage/modules/{other_module}/lessons/order', ['lesson_ids' => []]],
            'read a lesson' => ['GET', '/manage/lessons/{other_lesson}', []],
            'update a lesson' => ['PATCH', '/manage/lessons/{other_lesson}', ['title' => 'Mine now']],
            'move a lesson' => ['POST', '/manage/lessons/{other_lesson}/move', ['module_id' => 1]],
            'configure an assignment' => ['PUT', '/manage/lessons/{other_lesson}/assignment', ['max_points' => 5]],
            'list quizzes' => ['GET', '/manage/courses/{other_course}/quizzes', []],
            'create a quiz' => ['POST', '/manage/courses/{other_course}/quizzes', ['title' => 'Mine']],
            'read a quiz with its answers' => ['GET', '/manage/quizzes/{other_quiz}', []],
            'replace quiz settings' => ['PUT', '/manage/quizzes/{other_quiz}', ['title' => 'Mine']],
            'add a question' => [
                'POST',
                '/manage/quizzes/{other_quiz}/questions',
                ['type' => 'short_answer', 'definition' => ['prompt' => 'Why?', 'accepted' => []]],
            ],
            'reorder questions' => ['PUT', '/manage/quizzes/{other_quiz}/questions/order', ['question_ids' => []]],
            'update a question' => [
                'PUT',
                '/manage/questions/{other_question}',
                ['definition' => ['prompt' => 'Why?', 'accepted' => []], 'points' => 1],
            ],
            'remove a question' => ['DELETE', '/manage/questions/{other_question}', []],
            'read the gradebook' => ['GET', '/manage/courses/{other_course}/gradebook', []],
            'read the roster' => ['GET', '/manage/courses/{other_course}/learners', []],
            'enrol a learner' => ['POST', '/manage/courses/{other_course}/learners', ['user_id' => 50]],
            'read one learner' => ['GET', '/manage/courses/{other_course}/learners/50', []],
            'revoke an entitlement' => ['POST', '/manage/entitlements/{other_entitlement}/revoke', []],
            'extend an entitlement' => ['POST', '/manage/entitlements/{other_entitlement}/extend', ['expires_at' => null]],
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('courseScopedActions')]
    public function testATeacherOfAnotherCourseIsRefused(string $method, string $pattern, array $body): void
    {
        $second = $this->seedSecondCourse();

        $response = $this->fixture->call($method, strtr($pattern, $second), $this->fixture->teacher(), $body);

        self::assertSame(403, $response->status, $method . ' ' . $pattern);
    }

    /**
     * @param array<string, mixed> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('courseScopedActions')]
    public function testAManagerIsServedOnEveryCourse(string $method, string $pattern, array $body): void
    {
        $second = $this->seedSecondCourse();

        $response = $this->fixture->call($method, strtr($pattern, $second), $this->fixture->manager(), $body);

        self::assertNotSame(403, $response->status, $method . ' ' . $pattern);
        self::assertNotSame(401, $response->status, $method . ' ' . $pattern);
        self::assertNotSame(404, $response->status, $method . ' ' . $pattern);
    }

    public function testTheSameTeacherIsServedOnTheirOwnCourse(): void
    {
        $teacher = $this->fixture->teacher();

        self::assertSame(200, $this->fixture->call('GET', $this->fixture->path('/manage/courses/{course}'), $teacher)->status);
        self::assertSame(
            200,
            $this->fixture->call('GET', $this->fixture->path('/manage/courses/{course}/curriculum'), $teacher)->status,
        );
        self::assertSame(
            200,
            $this->fixture->call('PATCH', $this->fixture->path('/manage/courses/{course}'), $teacher, ['level' => 'beginner'])->status,
        );
        self::assertSame(200, $this->fixture->call('GET', $this->fixture->path('/manage/quizzes/{quiz}'), $teacher)->status);
        self::assertSame(
            200,
            $this->fixture->call('GET', $this->fixture->path('/manage/courses/{course}/gradebook'), $teacher)->status,
        );
    }

    public function testTheCourseListNarrowsToTheCoursesTheCallerInstructs(): void
    {
        $mine = $this->fixture->call('GET', '/manage/courses', $this->fixture->teacher());
        $every = $this->fixture->call('GET', '/manage/courses', $this->fixture->manager());

        self::assertNotNull($mine->data);
        self::assertNotNull($every->data);
        self::assertSame(1, $mine->data['total']);
        self::assertSame($this->fixture->courseId, $mine->data['items'][0]['id']);
        self::assertSame(2, $every->data['total']);
    }

    public function testACallerWithNoCourseCapabilityIsRefusedTheListOutright(): void
    {
        $response = $this->fixture->call('GET', '/manage/courses', $this->fixture->learner());

        self::assertSame(403, $response->status);
    }

    public function testTheCatalogueAndTheLearnerRoutesAreUnaffected(): void
    {
        $learner = $this->fixture->learner();

        $catalogue = $this->fixture->call('GET', '/courses', Principal::guest());
        self::assertSame(200, $catalogue->status);
        self::assertNotNull($catalogue->data);
        self::assertSame(1, $catalogue->data['total'], 'The published course is still public.');

        $course = $this->fixture->call('GET', '/courses/tea-tasting', Principal::guest());
        self::assertSame(200, $course->status);

        $curriculum = $this->fixture->call(
            'GET',
            '/courses/' . $this->fixture->courseId . '/curriculum',
            $learner,
        );
        self::assertSame(200, $curriculum->status);

        $enrolments = $this->fixture->call('GET', '/me/enrolments', $learner);
        self::assertSame(200, $enrolments->status);
        self::assertNotNull($enrolments->data);
        self::assertCount(1, $enrolments->data['items']);
    }

    public function testADraftCourseIsNotFoundForATeacherWhoDoesNotInstructIt(): void
    {
        $outsider = $this->fixture->outsider();
        $slug = 'coffee-roasting';

        // The outsider authored it, so they see their own draft.
        self::assertSame(200, $this->fixture->call('GET', '/courses/' . $slug, $outsider)->status);

        // The other teacher holds the same capability and is not an
        // instructor, so the draft does not exist as far as they can tell.
        self::assertSame(404, $this->fixture->call('GET', '/courses/' . $slug, $this->fixture->teacher())->status);
        self::assertSame(
            404,
            $this->fixture->call('GET', '/courses/' . $this->fixture->otherCourseId . '/curriculum', $this->fixture->teacher())->status,
        );
    }

    public function testADraftCourseCannotBeNamedAsAPrerequisiteByATeacherWhoDoesNotInstructIt(): void
    {
        $teacher = $this->fixture->teacher();
        $other = $this->fixture->otherCourseId;

        $refused = $this->fixture->call(
            'PUT',
            $this->fixture->path('/manage/courses/{course}/prerequisites'),
            $teacher,
            ['course_ids' => [$other]],
        );

        // The same answer a missing id gets, so the route cannot tell an
        // existing draft apart from a course that is not there, and nothing
        // of the other course's row travels back.
        $missing = $this->fixture->call(
            'PUT',
            $this->fixture->path('/manage/courses/{course}/prerequisites'),
            $teacher,
            ['course_ids' => [99999]],
        );

        self::assertSame(404, $refused->status);
        self::assertSame(404, $missing->status);
        self::assertNotNull($refused->data);
        self::assertNotNull($missing->data);
        self::assertSame($missing->data['title'], $refused->data['title']);
        self::assertStringNotContainsString('Coffee', json_encode($refused->data, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('coffee-roasting', json_encode($refused->data, \JSON_THROW_ON_ERROR));

        $stored = $this->fixture->call('GET', $this->fixture->path('/manage/courses/{course}'), $teacher);
        self::assertSame(200, $stored->status);
        self::assertNotNull($stored->data);
        self::assertSame([], $stored->data['prerequisites']);
    }

    public function testAPublishedCourseIsAcceptedAsAPrerequisiteByAnyTeacherOfTheEditedCourse(): void
    {
        $this->seedSecondCourse();
        $outsider = $this->fixture->outsider();
        $published = $this->fixture->call('POST', '/manage/courses/' . $this->fixture->otherCourseId . '/publish', $outsider);
        self::assertSame(200, $published->status);

        $response = $this->fixture->call(
            'PUT',
            $this->fixture->path('/manage/courses/{course}/prerequisites'),
            $this->fixture->teacher(),
            ['course_ids' => [$this->fixture->otherCourseId]],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame($this->fixture->otherCourseId, $response->data['items'][0]['id']);
    }

    public function testAManagerMayNameADraftCourseAsAPrerequisite(): void
    {
        $response = $this->fixture->call(
            'PUT',
            $this->fixture->path('/manage/courses/{course}/prerequisites'),
            $this->fixture->manager(),
            ['course_ids' => [$this->fixture->otherCourseId]],
        );

        self::assertSame(200, $response->status);
    }

    /**
     * Course B, built by the outsider, with a module, a lesson, a quiz, a
     * question and an entitlement of its own.
     *
     * @return array<string, string>
     */
    private function seedSecondCourse(): array
    {
        $outsider = $this->fixture->outsider();
        $courseId = $this->fixture->otherCourseId;

        $module = $this->fixture->call('POST', '/manage/courses/' . $courseId . '/modules', $outsider, ['title' => 'Roasting']);
        self::assertSame(201, $module->status);
        self::assertNotNull($module->data);

        $lesson = $this->fixture->call('POST', '/manage/modules/' . $module->data['id'] . '/lessons', $outsider, [
            'title' => 'Roast profiles',
            'type' => 'assignment',
        ]);
        self::assertSame(201, $lesson->status);
        self::assertNotNull($lesson->data);

        $quiz = $this->fixture->call('POST', '/manage/courses/' . $courseId . '/quizzes', $outsider, ['title' => 'Roast quiz']);
        self::assertSame(201, $quiz->status);
        self::assertNotNull($quiz->data);

        $question = $this->fixture->call('POST', '/manage/quizzes/' . $quiz->data['id'] . '/questions', $outsider, [
            'type' => 'short_answer',
            'definition' => ['prompt' => 'Describe a light roast.', 'accepted' => []],
        ]);
        self::assertSame(201, $question->status);
        self::assertNotNull($question->data);

        $grant = $this->fixture->call('POST', '/manage/courses/' . $courseId . '/learners', $outsider, [
            'user_id' => ManagementFixture::LEARNER_ID,
        ]);
        self::assertSame(201, $grant->status);
        self::assertNotNull($grant->data);

        return [
            '{other_course}' => (string) $courseId,
            '{other_module}' => (string) $module->data['id'],
            '{other_lesson}' => (string) $lesson->data['id'],
            '{other_quiz}' => (string) $quiz->data['id'],
            '{other_question}' => (string) $question->data['id'],
            '{other_entitlement}' => (string) $grant->data['entitlement']['id'],
        ];
    }
}
