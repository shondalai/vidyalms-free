<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class AdversarialAccessTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): void
    {
        $this->fixture->env->database->insert($table, $row + [
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    public function testInstructorTimelineExcludesOtherCoursesAndSiteActivity(): void
    {
        $f = $this->fixture;
        $f->env->database->delete('activity_log', ['user_id' => 50]);
        foreach ([$f->courseId, $f->otherCourseId, null] as $courseId) {
            $this->insert('activity_log', [
                'user_id' => 50, 'course_id' => $courseId, 'action' => 'sign.in',
                'occurred_at' => '2026-01-01 10:00:00',
            ]);
        }
        $response = $f->call('GET', '/manage/learners/50/timeline', $f->teacher());
        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(1, $response->data['totalItems']);
        self::assertSame($f->courseId, $response->data['orderedItems'][0]['target']['id']);
        $response = $f->call('GET', '/manage/learners/50/timeline', $f->teacher(), query: ['course_id' => (string) $f->otherCourseId]);
        self::assertSame(403, $response->status);
    }

    public function testDraftModuleCannotBeReadOrSubmittedTo(): void
    {
        $f = $this->fixture;
        $f->env->database->update('modules', ['state' => 'draft'], ['id' => $f->moduleId]);
        self::assertSame(404, $f->call('GET', '/lessons/' . $f->lessonId, $f->learner())->status);
        self::assertSame(404, $f->call(
            'POST',
            '/assignments/' . $f->assignmentLessonId . '/submissions',
            $f->learner(),
            ['text_response' => 'Forged submission', 'files' => []]
        )->status);
        self::assertSame(0, $f->env->database->count(Query::from('submissions')));
    }

    public function testRevokedLearnerCannotCreateAnotherOpenEntitlement(): void
    {
        $f = $this->fixture;
        $f->env->database->update('courses', ['price_mode' => 'free'], ['id' => $f->courseId]);
        $f->env->database->update('enrolments', ['status' => 'cancelled'], ['course_id' => $f->courseId, 'user_id' => 50]);
        $before = $f->env->database->count(Query::from('entitlements'));
        self::assertFalse($f->kernel->selfEnrol->isAvailableFor($f->learner(), $f->courseId));
        self::assertSame(409, $f->call('POST', '/enrolments', $f->learner(), ['course_id' => $f->courseId])->status);
        self::assertSame($before, $f->env->database->count(Query::from('entitlements')));
    }

    public function testPrerequisitesMustBeCompletedBeforeSelfEnrolment(): void
    {
        $f = $this->fixture;
        $f->env->users->add(77, 'New learner', 'new@example.test');
        $f->env->database->update('courses', ['price_mode' => 'free'], ['id' => $f->courseId]);
        $this->insert('course_prerequisites', ['course_id' => $f->courseId, 'prerequisite_course_id' => $f->otherCourseId]);
        self::assertSame(409, $f->call('POST', '/enrolments', Principal::user(77), ['course_id' => $f->courseId])->status);
        $this->insert('enrolments', [
            'user_id' => 77, 'course_id' => $f->otherCourseId, 'entitlement_id' => $f->entitlementId,
            'status' => 'completed', 'enrolled_at' => '2026-01-01 00:00:00',
        ]);
        self::assertSame(201, $f->call('POST', '/enrolments', Principal::user(77), ['course_id' => $f->courseId])->status);
    }

    public function testSavedEnrolmentDateRuleIsEnforcedByTheWriteRoute(): void
    {
        $f = $this->fixture;
        $f->env->users->add(77, 'New learner', 'new@example.test');
        $f->env->database->update('courses', ['price_mode' => 'free'], ['id' => $f->courseId]);
        $saved = $f->call('PUT', '/manage/courses/' . $f->courseId . '/availability', $f->teacher(), [
            'tree' => ['op' => 'and', 'c' => [[
                'type' => 'date', 'payload' => ['_v' => 1, 'from' => '2099-01-01T00:00:00+00:00', 'tz' => 'UTC'],
            ]]],
        ], ['surface' => 'enrolment_gating']);
        self::assertSame(200, $saved->status);
        self::assertFalse($f->kernel->selfEnrol->isAvailableFor(Principal::user(77), $f->courseId));
        self::assertSame(409, $f->call('POST', '/enrolments', Principal::user(77), ['course_id' => $f->courseId])->status);
        $f->call(
            'PUT',
            '/manage/courses/' . $f->courseId . '/availability',
            $f->teacher(),
            ['tree' => ['op' => 'and', 'c' => []]],
            ['surface' => 'enrolment_gating']
        );
        self::assertSame(201, $f->call('POST', '/enrolments', Principal::user(77), ['course_id' => $f->courseId])->status);
    }

    public function testExistingEnrolmentDoesNotBypassCoursePrerequisites(): void
    {
        $f = $this->fixture;
        $this->insert('course_prerequisites', ['course_id' => $f->courseId, 'prerequisite_course_id' => $f->otherCourseId]);
        $blocked = $f->call('GET', '/lessons/' . $f->lessonId, $f->learner());
        self::assertSame(200, $blocked->status);
        self::assertNotNull($blocked->data);
        self::assertFalse($blocked->data['content_available']);
        self::assertSame('blocked', $blocked->data['access']['state']);
        self::assertSame(409, $f->call(
            'POST',
            '/assignments/' . $f->assignmentLessonId . '/submissions',
            $f->learner(),
            ['text_response' => 'Blocked', 'files' => []]
        )->status);
        $this->insert('enrolments', ['user_id' => 50, 'course_id' => $f->otherCourseId,
            'entitlement_id' => $f->entitlementId, 'status' => 'completed', 'enrolled_at' => '2026-01-01 00:00:00']);
        self::assertSame(200, $f->call('GET', '/lessons/' . $f->lessonId, $f->learner())->status);
    }

    public function testInstructorCannotUseCourseRouteToReadAManagerReport(): void
    {
        $f = $this->fixture;
        $f->kernel->registries->reports->register(new class () implements
            \Shondalai\VidyaLMS\Core\Registry\Report\ReportProviderInterface,
            \Shondalai\VidyaLMS\Core\Registry\Report\CourseScopedReport
        {
            public function key(): string
            {
                return 'manager_audit';
            }
            public function label(): string
            {
                return 'Manager audit';
            }
            public function requiredCapability(): string
            {
                return \Shondalai\VidyaLMS\Core\Application\Shared\Capability::MANAGE;
            }
            public function generate(\Shondalai\VidyaLMS\Core\Registry\Context\ReportContext $context): array
            {
                return ['items' => [], 'total' => 0];
            }
        });
        $path = '/manage/courses/' . $f->courseId . '/reports/manager_audit';
        self::assertSame(403, $f->call('GET', $path, $f->teacher())->status);
        self::assertSame(200, $f->call('GET', $path, $f->manager())->status);
    }

    public function testHiddenQuizResultsDoNotEscapeThroughProgressOrTimeline(): void
    {
        $f = $this->fixture;
        $f->env->database->update('quizzes', ['show_results' => 'never'], ['id' => $f->quizId]);
        $enrolment = $f->env->database->selectOne(Query::from('enrolments')->where('user_id', '=', 50)->where('course_id', '=', $f->courseId));
        self::assertNotNull($enrolment);
        $this->insert('course_progress', [
            'enrolment_id' => $enrolment['id'], 'quiz_average' => 83, 'final_quiz_passed' => 1,
            'lessons_total' => 3, 'lessons_completed' => 1, 'percent' => 33, 'computed_at' => '2026-01-01 00:00:00',
        ]);
        $progress = $f->kernel->getMyCourseProgress->execute($f->learner(), (int) $enrolment['id']);
        self::assertNotNull($progress->summary);
        self::assertNull($progress->summary->quizAverage);
        self::assertNull($progress->summary->finalQuizPassed);
        $this->insert('activity_log', [
            'user_id' => 50, 'course_id' => $f->courseId, 'action' => 'vidyalms.attempt.submitted',
            'occurred_at' => '2026-01-01 10:00:00',
            'context' => json_encode(['quiz_id' => $f->quizId, 'percentage' => 83, 'passed' => true, 'feedback' => 'Answer key']),
        ]);
        $timeline = $f->call('GET', '/me/timeline', $f->learner());
        self::assertSame(200, $timeline->status);
        self::assertNotNull($timeline->data);
        foreach ($timeline->data['orderedItems'] as $item) {
            self::assertArrayNotHasKey('percentage', $item['context']);
            self::assertArrayNotHasKey('passed', $item['context']);
            self::assertArrayNotHasKey('feedback', $item['context']);
        }
        $f->env->database->update('quizzes', ['show_results' => 'after_submit', 'review_visibility' => '{}'], ['id' => $f->quizId]);
        $visible = $f->kernel->getMyCourseProgress->execute($f->learner(), (int) $enrolment['id']);
        self::assertNotNull($visible->summary);
        self::assertSame(83.0, $visible->summary->quizAverage);
    }

    public function testForgedVideoCompletionAndHeartbeatReplayCannotCreateTime(): void
    {
        $f = $this->fixture;
        $f->env->database->update('lessons', ['type' => 'video', 'duration_seconds' => 100], ['id' => $f->lessonId]);
        $enrolment = $f->env->database->selectOne(Query::from('enrolments')->where('user_id', '=', 50)->where('course_id', '=', $f->courseId));
        self::assertNotNull($enrolment);
        $record = fn () => $f->kernel->recordLessonProgress->execute($f->learner(), (int) $enrolment['id'], $f->lessonId, 100, 99999, 99999, true);
        $record();
        $record();
        $row = $f->env->database->selectOne(Query::from('lesson_progress')->where('lesson_id', '=', $f->lessonId));
        self::assertNotNull($row);
        self::assertSame(0, (int) $row['seconds_spent']);
        self::assertNull($row['completed_at']);
        $f->env->clock->advance('PT90S');
        $record();
        $record();
        $row = $f->env->database->selectOne(Query::from('lesson_progress')->where('lesson_id', '=', $f->lessonId));
        self::assertNotNull($row);
        self::assertSame(90, (int) $row['seconds_spent']);
        self::assertNotNull($row['completed_at']);
    }
}
