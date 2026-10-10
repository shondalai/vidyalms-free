<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Progress;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Events\LessonCompleted;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Availability\DateCondition;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

final class ImportedLessonProgressTest extends TestCase
{
    private ManagementFixture $fixture;
    private int $enrolmentId;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->database->update('courses', ['state' => 'draft', 'visibility' => 'private'], [
            'id' => $this->fixture->courseId,
        ]);
        $enrolment = $this->fixture->env->database->selectOne(Query::from('enrolments')
            ->where('course_id', '=', $this->fixture->courseId)->where('user_id', '=', ManagementFixture::LEARNER_ID));
        self::assertNotNull($enrolment);
        $this->enrolmentId = (int) $enrolment['id'];
    }

    public function testImportedHistoryBelongsToTheLearnerAndDoesNotExposeTheDraft(): void
    {
        $this->importCompletion();
        $this->importCompletion();

        $row = $this->fixture->env->database->selectOne(Query::from('lesson_progress'));
        self::assertNotNull($row);
        self::assertSame(ManagementFixture::LEARNER_ID, (int) $row['user_id']);
        self::assertSame('completed', $row['status']);
        self::assertSame(100.0, (float) $row['progress_percent']);
        self::assertSame(1, $this->fixture->env->database->count(Query::from('lesson_progress')));

        $events = array_values(array_filter(
            $this->fixture->env->eventBridge->published,
            static fn (object $event): bool => $event instanceof LessonCompleted
        ));
        self::assertCount(1, $events);
        self::assertInstanceOf(LessonCompleted::class, $events[0]);
        self::assertSame(ManagementFixture::LEARNER_ID, $events[0]->userId);

        $course = $this->fixture->env->database->selectOne(Query::from('courses')->where('id', '=', $this->fixture->courseId));
        self::assertSame('draft', $course['state'] ?? null);
        self::assertSame('private', $course['visibility'] ?? null);
        foreach ([Principal::guest(), Principal::user(77), $this->fixture->learner()] as $principal) {
            foreach (
                ['/courses/tea-tasting', '/courses/' . $this->fixture->courseId . '/curriculum',
                '/lessons/' . $this->fixture->lessonId] as $path
            ) {
                self::assertSame(404, $this->fixture->call('GET', $path, $principal)->status, $path);
            }
        }
    }

    /** @return array<string, array{int}> */
    public static function unauthorisedOperators(): array
    {
        return ['guest' => [0], 'learner' => [ManagementFixture::LEARNER_ID],
            'instructor' => [ManagementFixture::TEACHER_ID], 'other instructor' => [ManagementFixture::OUTSIDER_ID],
            'course-only manager' => [77]];
    }

    #[DataProvider('unauthorisedOperators')]
    public function testOnlyASiteManagerCanImportCompletions(int $userId): void
    {
        $this->fixture->env->authorization->grant(77, Capability::MANAGE, CourseAccess::resource($this->fixture->courseId));
        try {
            $this->fixture->kernel->recordLessonProgress->importCompletion(
                $userId === 0 ? Principal::guest() : Principal::user($userId),
                $this->enrolmentId,
                $this->fixture->lessonId,
            );
            self::fail('Import completion requires site management permission.');
        } catch (NotAuthorizedException) {
            $this->assertNoProgress();
        }
    }

    /** @return array<string, array{string, string}> */
    public static function unavailableStructure(): array
    {
        return ['trashed course' => ['courses', 'trashed'], 'draft module' => ['modules', 'draft'],
            'archived module' => ['modules', 'archived'], 'draft lesson' => ['lessons', 'draft'],
            'archived lesson' => ['lessons', 'archived']];
    }

    #[DataProvider('unavailableStructure')]
    public function testImportStillRequiresReachableLessonStructure(string $table, string $state): void
    {
        $id = match ($table) {
            'courses' => $this->fixture->courseId,
            'modules' => $this->fixture->moduleId,
            default => $this->fixture->lessonId,
        };
        $this->fixture->env->database->update($table, ['state' => $state], ['id' => $id]);
        try {
            $this->importCompletion();
            self::fail('An unavailable lesson accepted imported progress.');
        } catch (EntityNotFoundException) {
            $this->assertNoProgress();
        }
    }

    /** @return array<string, array{string}> */
    public static function inactiveEnrolments(): array
    {
        return ['suspended' => ['suspended'], 'cancelled' => ['cancelled']];
    }

    #[DataProvider('inactiveEnrolments')]
    public function testImportDoesNotReactivateEnrolment(string $status): void
    {
        $this->fixture->env->database->update('enrolments', ['status' => $status], ['id' => $this->enrolmentId]);
        try {
            $this->importCompletion();
            self::fail('An inactive enrolment accepted imported progress.');
        } catch (ConflictException) {
            $this->assertNoProgress();
            $row = $this->fixture->env->database->selectOne(Query::from('enrolments')->where('id', '=', $this->enrolmentId));
            self::assertSame($status, $row['status'] ?? null);
        }
    }

    public function testTheLessonMustBelongToTheEnrolledCourse(): void
    {
        $this->fixture->env->database->update('enrolments', ['course_id' => $this->fixture->otherCourseId], ['id' => $this->enrolmentId]);
        try {
            $this->importCompletion();
            self::fail('A lesson from another course accepted imported progress.');
        } catch (EntityNotFoundException) {
            $this->assertNoProgress();
        }
    }

    public function testHeartbeatCannotOptIntoImportAuthority(): void
    {
        $response = $this->fixture->call('POST', '/lessons/' . $this->fixture->lessonId . '/progress', $this->fixture->learner(), [
            'enrolment_id' => $this->enrolmentId, 'completed' => true, 'importing' => true,
            'allow_draft_course' => true, 'operator_id' => ManagementFixture::MANAGER_ID,
        ], [], 'draft-heartbeat');
        self::assertSame(404, $response->status);
        $this->assertNoProgress();
    }

    public function testImportDoesNotBypassAnAvailabilityRestriction(): void
    {
        $response = $this->fixture->call(
            'PUT',
            '/manage/lessons/' . $this->fixture->lessonId . '/availability',
            $this->fixture->teacher(),
            ['tree' => ['op' => 'and', 'c' => [[
                'type' => DateCondition::KEY,
                'payload' => StoredShape::wrap(['from' => '2030-01-01T00:00:00+00:00', 'until' => null, 'tz' => 'UTC'], 1),
            ]]]]
        );
        self::assertSame(200, $response->status);
        try {
            $this->importCompletion();
            self::fail('Import bypassed a lesson availability restriction.');
        } catch (ConflictException) {
            $this->assertNoProgress();
        }
    }

    /** @return array<string, array{string}> */
    public static function assessedLessons(): array
    {
        return ['quiz' => ['quizLessonId'], 'assignment' => ['assignmentLessonId']];
    }

    #[DataProvider('assessedLessons')]
    public function testImportCannotInventAnAssessmentPass(string $property): void
    {
        $this->fixture->kernel->recordLessonProgress->importCompletion(
            $this->fixture->manager(),
            $this->enrolmentId,
            $this->fixture->{$property},
        );
        $row = $this->fixture->env->database->selectOne(Query::from('lesson_progress'));
        self::assertSame('in_progress', $row['status'] ?? null);
        self::assertSame(0, $this->fixture->env->database->count(Query::from('attempts')));
        self::assertSame(0, $this->fixture->env->database->count(Query::from('submissions')));
    }

    private function importCompletion(): void
    {
        $this->fixture->kernel->recordLessonProgress->importCompletion(
            $this->fixture->manager(),
            $this->enrolmentId,
            $this->fixture->lessonId,
        );
    }

    private function assertNoProgress(): void
    {
        self::assertSame(0, $this->fixture->env->database->count(Query::from('lesson_progress')));
        self::assertNotContains('vidyalms.lesson.completed', $this->fixture->env->eventBridge->eventNames());
    }
}
