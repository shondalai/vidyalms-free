<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Curriculum\ArchiveCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\PublishCourse;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseState;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class PublishCourseTest extends CurriculumTestCase
{
    private function publish(): PublishCourse
    {
        return new PublishCourse($this->courses, $this->lessons, $this->access, $this->env->clock, $this->events);
    }

    public function testPublishingWithoutAnyLessonIsBlocked(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);

        $this->expectException(ConflictException::class);
        $this->publish()->execute($teacher, $course->id);
    }

    public function testPublishingWithOnlyDraftLessonsIsBlocked(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $module = $this->newModule($teacher, $course->id);
        $this->newReadingLesson($teacher, $module->id, 'Draft only', Lesson::STATE_DRAFT);

        $this->expectException(ConflictException::class);
        $this->publish()->execute($teacher, $course->id);
    }

    public function testPublishingSetsStateAndTimestampFromTheClock(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $module = $this->newModule($teacher, $course->id);
        $this->newReadingLesson($teacher, $module->id);

        $this->env->clock->set('2026-03-01 12:30:00');
        $published = $this->publish()->execute($teacher, $course->id);

        self::assertSame(CourseState::Published, $published->state);
        self::assertNotNull($published->publishedAt);
        self::assertSame('2026-03-01 12:30:00', $published->publishedAt->format('Y-m-d H:i:s'));

        $row = $this->env->database->selectOne(Query::from('courses')->where('id', '=', $course->id));
        self::assertNotNull($row);
        self::assertSame('published', $row['state']);
        self::assertSame('2026-03-01 12:30:00', $row['published_at']);
    }

    public function testRepublishingKeepsTheOriginalTimestamp(): void
    {
        $teacher = $this->teacher();
        $course = $this->publishedCourse($teacher);

        $this->env->clock->advance('P1D');
        $again = $this->publish()->execute($teacher, $course->id);

        self::assertNotNull($again->publishedAt);
        self::assertSame('2026-01-15 10:00:00', $again->publishedAt->format('Y-m-d H:i:s'));
    }

    public function testUnknownCourseIsNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->publish()->execute($this->teacher(), 424242);
    }

    public function testGuestMayNotPublish(): void
    {
        $course = $this->newCourse($this->teacher());

        $this->expectException(NotAuthorizedException::class);
        $this->publish()->execute(Principal::guest(), $course->id);
    }

    public function testArchivingAPublishedCourse(): void
    {
        $teacher = $this->teacher();
        $course = $this->publishedCourse($teacher);

        $archived = (new ArchiveCourse($this->courses, $this->access, $this->events, $this->env->clock))->execute($teacher, $course->id);

        self::assertSame(CourseState::Archived, $archived->state);
        self::assertNotNull($archived->publishedAt);

        $row = $this->env->database->selectOne(Query::from('courses')->where('id', '=', $course->id));
        self::assertNotNull($row);
        self::assertSame('archived', $row['state']);
    }

    public function testNonTeacherMayNotArchive(): void
    {
        $course = $this->newCourse($this->teacher());

        $this->expectException(NotAuthorizedException::class);
        (new ArchiveCourse($this->courses, $this->access, $this->events, $this->env->clock))->execute($this->learner(), $course->id);
    }
}
