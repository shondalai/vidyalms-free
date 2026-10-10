<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ArchiveOutcome;
use Shondalai\VidyaLMS\Core\Application\Progress\RecomputeCourseProgressForCourse;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;

/**
 * Archiving a lesson or a module: a reversible removal that leaves every
 * record of what a learner did, and the one consequence that is not obvious
 * from the word "archive". Removing the last thing a learner had not done
 * finishes the course for them, and nothing else would notice, because a
 * finished learner sends no more heartbeats.
 */
final class ArchiveCurriculumTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testArchivingALessonTakesItOutOfTheLearnerCurriculum(): void
    {
        $response = $this->fixture->call(
            'DELETE',
            '/manage/lessons/' . $this->fixture->lessonId,
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['lesson', 'outcome'], array_keys($response->data));
        self::assertSame('archived', $response->data['lesson']['state']);

        $curriculum = $this->fixture->call(
            'GET',
            '/courses/' . $this->fixture->courseId . '/curriculum',
            $this->fixture->learner(),
        );

        self::assertNotNull($curriculum->data);
        $titles = [];

        foreach ($curriculum->data['modules'] as $section) {
            foreach ($section['lessons'] as $lesson) {
                $titles[] = $lesson['title'];
            }
        }

        self::assertNotContains('Welcome', $titles, 'an archived lesson is not part of the course any more');
    }

    public function testTheAuthorStillSeesItSoItCanBeRestored(): void
    {
        $teacher = $this->fixture->teacher();
        $this->fixture->call('DELETE', '/manage/lessons/' . $this->fixture->lessonId, $teacher);

        $authoring = $this->fixture->call(
            'GET',
            '/manage/courses/' . $this->fixture->courseId . '/curriculum',
            $teacher,
        );

        self::assertNotNull($authoring->data);
        $states = [];

        foreach ($authoring->data['modules'] as $section) {
            foreach ($section['lessons'] as $lesson) {
                $states[$lesson['id']] = $lesson['state'];
            }
        }

        self::assertSame('archived', $states[$this->fixture->lessonId] ?? null);

        $restored = $this->fixture->call('PATCH', '/manage/lessons/' . $this->fixture->lessonId, $teacher, [
            'state' => 'published',
        ]);

        self::assertSame(200, $restored->status);
        self::assertNotNull($restored->data);
        self::assertSame('published', $restored->data['state']);
    }

    public function testProgressAttemptsAndGradesSurviveTheArchive(): void
    {
        $enrolmentId = $this->enrolmentId();
        $this->completeLesson($enrolmentId, $this->fixture->lessonId);

        $this->fixture->call('DELETE', '/manage/lessons/' . $this->fixture->lessonId, $this->fixture->teacher());

        $rows = $this->fixture->env->database->selectAll(
            Query::from('lesson_progress')->where('lesson_id', '=', $this->fixture->lessonId),
        );

        self::assertCount(1, $rows, 'progress is append-only; archiving is an authoring decision');
        self::assertSame('completed', $rows[0]['status']);
    }

    public function testArchivingQueuesACourseWideRecomputeAndSaysSo(): void
    {
        $response = $this->fixture->call(
            'DELETE',
            '/manage/lessons/' . $this->fixture->lessonId,
            $this->fixture->teacher(),
        );

        self::assertNotNull($response->data);
        self::assertSame(
            [
                'lessons_archived' => 1,
                'enrolments_affected' => 1,
                'progress_recompute' => ArchiveOutcome::RECOMPUTE_QUEUED,
            ],
            $response->data['outcome'],
        );

        // Filtered by type rather than counted whole, because the fixture's
        // own enrolment queues a notification fan-out of its own now that a
        // committed intent row wakes its handler. What this test is about is
        // that archiving queues exactly one recompute for the course.
        $tasks = array_values(array_filter(
            $this->fixture->env->scheduler->tasks,
            static fn (ScheduledTask $task): bool => $task->type === RecomputeCourseProgressForCourse::TASK_TYPE,
        ));
        self::assertCount(1, $tasks);
        self::assertSame(RecomputeCourseProgressForCourse::TASK_TYPE, $tasks[0]->type);
        self::assertSame(
            ['course_id' => $this->fixture->courseId, 'after_enrolment_id' => 0],
            $tasks[0]->payload,
        );
        self::assertSame(
            RecomputeCourseProgressForCourse::dedupeKeyFor($this->fixture->courseId),
            $tasks[0]->dedupeKey,
        );
    }

    public function testArchivingTheLastUnfinishedLessonCompletesTheCourseWhenTheTaskRuns(): void
    {
        $enrolmentId = $this->enrolmentId();

        // Everything done except the assignment lesson, which is the one
        // that gets archived.
        $this->completeLesson($enrolmentId, $this->fixture->lessonId);
        $this->completeLesson($enrolmentId, $this->fixture->quizLessonId);
        $this->fixture->kernel->recomputeCourseProgress->execute(
            Principal::user(ManagementFixture::LEARNER_ID),
            $enrolmentId,
        );

        self::assertSame('active', $this->enrolmentStatus($enrolmentId), 'one lesson is still outstanding');

        $this->fixture->call(
            'DELETE',
            '/manage/lessons/' . $this->fixture->assignmentLessonId,
            $this->fixture->teacher(),
        );

        self::assertSame('active', $this->enrolmentStatus($enrolmentId), 'the archive itself does not recompute');

        $this->runQueuedRecomputes();

        self::assertSame('completed', $this->enrolmentStatus($enrolmentId));
        self::assertNotSame([], array_filter(
            $this->fixture->env->eventBridge->published,
            static fn (object $event): bool => $event instanceof CourseCompleted,
        ), 'the completion fires the usual event, so the certificate and the mail follow');
    }

    public function testArchivingAModuleArchivesItsLessons(): void
    {
        $response = $this->fixture->call(
            'DELETE',
            '/manage/modules/' . $this->fixture->moduleId,
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['module', 'outcome'], array_keys($response->data));
        self::assertSame('archived', $response->data['module']['state']);
        self::assertSame(3, $response->data['outcome']['lessons_archived']);

        $states = $this->fixture->env->database->selectAll(
            Query::from('lessons')->select(['state'])->where('module_id', '=', $this->fixture->moduleId),
        );

        self::assertSame(
            ['archived'],
            array_values(array_unique(array_map(static fn (array $row): mixed => $row['state'], $states))),
        );
    }

    public function testArchivingTwiceQueuesNothingTheSecondTime(): void
    {
        $teacher = $this->fixture->teacher();
        $this->fixture->call('DELETE', '/manage/lessons/' . $this->fixture->lessonId, $teacher);
        $this->fixture->env->scheduler->tasks = [];

        $again = $this->fixture->call('DELETE', '/manage/lessons/' . $this->fixture->lessonId, $teacher);

        self::assertSame(200, $again->status);
        self::assertNotNull($again->data);
        self::assertSame(
            ArchiveOutcome::RECOMPUTE_NOT_NEEDED,
            $again->data['outcome']['progress_recompute'],
        );
        self::assertSame([], $this->fixture->env->scheduler->tasks);
    }

    public function testATeacherOfAnotherCourseMayNotArchive(): void
    {
        $refused = $this->fixture->call(
            'DELETE',
            '/manage/lessons/' . $this->fixture->lessonId,
            $this->fixture->outsider(),
        );

        self::assertSame(403, $refused->status);
    }

    public function testTheRecomputeTaskBatchesAndCarriesOn(): void
    {
        $courseId = $this->fixture->courseId;
        $now = $this->fixture->env->clock->now()->format('Y-m-d H:i:s');

        for ($index = 0; $index < RecomputeCourseProgressForCourse::BATCH_SIZE; $index++) {
            $userId = 900 + $index;
            $entitlementId = $this->fixture->env->database->insert('entitlements', [
                'user_id' => $userId,
                'course_id' => $courseId,
                'source' => 'manual',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->fixture->env->database->insert('enrolments', [
                'user_id' => $userId,
                'course_id' => $courseId,
                'entitlement_id' => $entitlementId,
                'status' => 'active',
                'enrolled_at' => $now,
                'progress_percent' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $done = $this->fixture->kernel->recomputeCourseProgressForCourse->execute($courseId);

        self::assertSame(RecomputeCourseProgressForCourse::BATCH_SIZE, $done);

        $tasks = $this->fixture->env->scheduler->tasks;
        $continuation = end($tasks);

        self::assertInstanceOf(ScheduledTask::class, $continuation);
        self::assertSame(RecomputeCourseProgressForCourse::TASK_TYPE, $continuation->type);
        self::assertGreaterThan(0, $continuation->payload['after_enrolment_id']);
    }

    private function enrolmentId(): int
    {
        $row = $this->fixture->env->database->selectOne(
            Query::from('enrolments')
                ->where('course_id', '=', $this->fixture->courseId)
                ->where('user_id', '=', ManagementFixture::LEARNER_ID),
        );

        self::assertNotNull($row);

        return (int) $row['id'];
    }

    private function enrolmentStatus(int $enrolmentId): string
    {
        $row = $this->fixture->env->database->selectOne(Query::from('enrolments')->where('id', '=', $enrolmentId));

        self::assertNotNull($row);

        return (string) $row['status'];
    }

    private function completeLesson(int $enrolmentId, int $lessonId): void
    {
        $now = $this->fixture->env->clock->now()->format('Y-m-d H:i:s');

        $this->fixture->env->database->insert('lesson_progress', [
            'enrolment_id' => $enrolmentId,
            'lesson_id' => $lessonId,
            'user_id' => ManagementFixture::LEARNER_ID,
            'status' => 'completed',
            'progress_percent' => 100,
            'seconds_spent' => 60,
            'position_seconds' => 0,
            'first_accessed_at' => $now,
            'completed_at' => $now,
            'last_heartbeat_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Drains the scheduler the way a host task runner would.
     */
    private function runQueuedRecomputes(): void
    {
        $guard = 0;

        while ($guard++ < 10) {
            $tasks = $this->fixture->env->scheduler->tasks;
            $this->fixture->env->scheduler->tasks = [];
            $ran = false;

            foreach ($tasks as $task) {
                if ($task->type !== RecomputeCourseProgressForCourse::TASK_TYPE) {
                    continue;
                }

                $ran = true;
                $this->fixture->kernel->recomputeCourseProgressForCourse->execute(
                    (int) $task->payload['course_id'],
                    (int) $task->payload['after_enrolment_id'],
                );
            }

            if (!$ran) {
                return;
            }
        }
    }
}
