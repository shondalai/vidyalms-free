<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Curriculum\GetCurriculum;
use Shondalai\VidyaLMS\Core\Application\Curriculum\PublishCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ReorderModules;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

final class GetCurriculumTest extends CurriculumTestCase
{
    private function useCase(): GetCurriculum
    {
        return new GetCurriculum($this->courses, $this->modules, $this->lessons, $this->access);
    }

    public function testReturnsModulesAndLessonsInPositionOrder(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $intro = $this->newModule($teacher, $course->id, 'Introduction');
        $advanced = $this->newModule($teacher, $course->id, 'Advanced');

        $this->newReadingLesson($teacher, $intro->id, 'Welcome');
        $this->newReadingLesson($teacher, $intro->id, 'Setup');
        $this->newReadingLesson($teacher, $advanced->id, 'Deep Dive');

        (new PublishCourse($this->courses, $this->lessons, $this->access, $this->env->clock, $this->events))
            ->execute($teacher, $course->id);

        // Flip the module order to prove position, not id, drives the listing.
        (new ReorderModules($this->modules, $this->access))
            ->execute($teacher, $course->id, [$advanced->id, $intro->id]);

        $sections = $this->useCase()->execute(Principal::guest(), $course->id);

        self::assertCount(2, $sections);
        self::assertSame('Advanced', $sections[0]->module->title);
        self::assertSame('Introduction', $sections[1]->module->title);
        self::assertSame(
            ['Deep Dive'],
            array_map(static fn (Lesson $lesson): string => $lesson->title, $sections[0]->lessons),
        );
        self::assertSame(
            ['Welcome', 'Setup'],
            array_map(static fn (Lesson $lesson): string => $lesson->title, $sections[1]->lessons),
        );
    }

    public function testLessonsCarryMetadataOnly(): void
    {
        $teacher = $this->teacher();
        $course = $this->publishedCourse($teacher);

        $sections = $this->useCase()->execute(Principal::guest(), $course->id);
        $lesson = $sections[0]->lessons[0];

        self::assertSame('Welcome', $lesson->title);
        self::assertSame('welcome', $lesson->slug->value);
        self::assertSame('reading', $lesson->type);
        self::assertFalse($lesson->freePreview);
        // The Lesson entity has no content field; the payload stays in
        // lesson_content and is only reachable through contentOf().
        self::assertFalse(property_exists($lesson, 'content'));
        self::assertFalse(property_exists($lesson, 'body'));
    }

    public function testDraftCurriculumIsHiddenFromGuestsButVisibleToTeachers(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $this->newModule($teacher, $course->id);

        self::assertCount(1, $this->useCase()->execute($teacher, $course->id));

        $this->expectException(EntityNotFoundException::class);
        $this->useCase()->execute(Principal::guest(), $course->id);
    }

    public function testUnknownCourseIsNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->useCase()->execute($this->teacher(), 999);
    }
}
