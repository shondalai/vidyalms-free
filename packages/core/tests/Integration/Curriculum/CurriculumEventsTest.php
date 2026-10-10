<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Curriculum;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseUpdated;
use Shondalai\VidyaLMS\Core\Domain\Events\LessonArchived;
use Shondalai\VidyaLMS\Core\Domain\Events\ModuleArchived;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The curriculum's own observability.
 *
 * Archiving a lesson or a module is the largest side effect in the context:
 * it can complete the course for a cohort, which fires CourseCompleted and
 * CertificateIssued for each of them minutes later. Both hosts and every
 * package need the cause, not just the storm.
 */
final class CurriculumEventsTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testArchivingALessonAnnouncesItself(): void
    {
        $this->fixture->env->eventBridge->published = [];

        $this->fixture->call(
            'DELETE',
            '/manage/lessons/' . $this->fixture->lessonId,
            $this->fixture->teacher(),
        );

        $event = $this->firstOf(LessonArchived::class);

        self::assertInstanceOf(LessonArchived::class, $event);
        self::assertSame('vidyalms.lesson.archived', $event->eventName());
        self::assertSame($this->fixture->courseId, $event->courseId);
        self::assertSame($this->fixture->lessonId, $event->lessonId);
        self::assertSame($this->fixture->moduleId, $event->moduleId);
        self::assertSame(1, $event->lessonsArchived);
        self::assertSame(1, $event->enrolmentsAffected, 'the one learner the fixture enrols');
        self::assertSame(ManagementFixture::TEACHER_ID, $event->actorId);
    }

    public function testArchivingAModuleIsOneOccurrenceCarryingItsSize(): void
    {
        $this->fixture->env->eventBridge->published = [];

        $this->fixture->call(
            'DELETE',
            '/manage/modules/' . $this->fixture->moduleId,
            $this->fixture->teacher(),
        );

        $event = $this->firstOf(ModuleArchived::class);

        self::assertInstanceOf(ModuleArchived::class, $event);
        self::assertSame($this->fixture->moduleId, $event->moduleId);
        self::assertSame(3, $event->lessonsArchived, 'the reading, quiz and assignment lessons went with it');
        self::assertSame(
            [],
            array_filter(
                $this->fixture->env->eventBridge->published,
                static fn (object $e): bool => $e instanceof LessonArchived,
            ),
            'one authoring action is one event, not one per lesson',
        );
    }

    public function testArchivingAnArchivedLessonAnnouncesNothing(): void
    {
        $teacher = $this->fixture->teacher();
        $this->fixture->call('DELETE', '/manage/lessons/' . $this->fixture->lessonId, $teacher);

        $this->fixture->env->eventBridge->published = [];
        $this->fixture->call('DELETE', '/manage/lessons/' . $this->fixture->lessonId, $teacher);

        self::assertNull($this->firstOf(LessonArchived::class), 'only the transition is an occurrence');
    }

    public function testTheArchiveLandsOnTheActivityLog(): void
    {
        $this->fixture->call(
            'DELETE',
            '/manage/lessons/' . $this->fixture->lessonId,
            $this->fixture->teacher(),
        );

        $row = $this->fixture->env->database->selectOne(
            Query::from('activity_log')->where('action', '=', 'vidyalms.lesson.archived'),
        );

        self::assertNotNull($row, 'the projector follows the interface, not a list it has to be edited into');
        self::assertSame(ManagementFixture::TEACHER_ID, (int) $row['user_id']);
        self::assertSame($this->fixture->lessonId, (int) $row['lesson_id']);
    }

    public function testCourseUpdatedStaysOffTheActivityLog(): void
    {
        $this->fixture->call('PATCH', '/manage/courses/' . $this->fixture->courseId, $this->fixture->teacher(), [
            'title' => 'Tea Tasting, Revised',
        ]);

        self::assertNotNull($this->firstOf(CourseUpdated::class), 'the event is still emitted');
        self::assertFalse(
            $this->firstOf(CourseUpdated::class) instanceof ActivityProjectable,
            'and opting out is simply not implementing the interface',
        );
        self::assertNull($this->fixture->env->database->selectOne(
            Query::from('activity_log')->where('action', '=', 'vidyalms.course.updated'),
        ));
    }

    public function testTwoSavesInsideOneSecondAreTwoOccurrences(): void
    {
        $teacher = $this->fixture->teacher();
        $courseId = $this->fixture->courseId;

        $this->fixture->call('PATCH', '/manage/courses/' . $courseId, $teacher, ['title' => 'Oolong Only']);
        $this->fixture->call('PATCH', '/manage/courses/' . $courseId, $teacher, ['slug' => 'oolong-only']);

        $keys = [];

        foreach ($this->fixture->env->eventBridge->published as $event) {
            if ($event instanceof CourseUpdated) {
                $keys[] = $event->idempotencyKey();
            }
        }

        self::assertCount(2, $keys);
        self::assertNotSame(
            $keys[0],
            $keys[1],
            'the clock is frozen in this harness, so only the changed list can tell them apart, '
            . 'which is exactly the autosaving form on a real host',
        );
    }

    public function testTheCourseListStopsCountingArchivedLessons(): void
    {
        $teacher = $this->fixture->teacher();

        $before = $this->fixture->call('GET', '/manage/courses', $teacher);
        self::assertNotNull($before->data);
        self::assertSame(3, $before->data['items'][0]['lesson_count']);
        self::assertSame(0, $before->data['items'][0]['archived_lesson_count']);

        $this->fixture->call('DELETE', '/manage/modules/' . $this->fixture->moduleId, $teacher);

        $after = $this->fixture->call('GET', '/manage/courses', $teacher);
        self::assertNotNull($after->data);

        self::assertSame(
            0,
            $after->data['items'][0]['lesson_count'],
            'the only number the course list shows must move when a module is archived',
        );
        self::assertSame(3, $after->data['items'][0]['archived_lesson_count']);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function firstOf(string $class): ?object
    {
        foreach ($this->fixture->env->eventBridge->published as $event) {
            if ($event instanceof $class) {
                return $event;
            }
        }

        return null;
    }
}
