<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Curriculum\SetPrerequisites;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class SetPrerequisitesTest extends CurriculumTestCase
{
    private function useCase(): SetPrerequisites
    {
        return new SetPrerequisites($this->courses, $this->access);
    }

    public function testReplacesTheWholePrerequisiteList(): void
    {
        $teacher = $this->teacher();
        $target = $this->newCourse($teacher, 'Advanced PHP');
        $basics = $this->newCourse($teacher, 'PHP Basics');
        $oop = $this->newCourse($teacher, 'OOP in PHP');

        $this->useCase()->execute($teacher, $target->id, [$basics->id, $oop->id]);
        self::assertSame([$basics->id, $oop->id], $this->courses->prerequisiteIds($target->id));

        $this->useCase()->execute($teacher, $target->id, [$oop->id]);
        self::assertSame([$oop->id], $this->courses->prerequisiteIds($target->id));

        self::assertSame(1, $this->env->database->count(
            Query::from('course_prerequisites')->where('course_id', '=', $target->id),
        ));
    }

    public function testClearingPrerequisitesWithAnEmptyList(): void
    {
        $teacher = $this->teacher();
        $target = $this->newCourse($teacher, 'Advanced PHP');
        $basics = $this->newCourse($teacher, 'PHP Basics');

        $this->useCase()->execute($teacher, $target->id, [$basics->id]);
        $this->useCase()->execute($teacher, $target->id, []);

        self::assertSame([], $this->courses->prerequisiteIds($target->id));
    }

    public function testSelfReferenceIsRejected(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);

        try {
            $this->useCase()->execute($teacher, $course->id, [$course->id]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('prerequisites', $exception->errors);
        }
    }

    public function testUnknownPrerequisiteCourseIsRejected(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);

        $this->expectException(EntityNotFoundException::class);
        $this->useCase()->execute($teacher, $course->id, [987654]);
    }

    public function testUnknownTargetCourseIsRejected(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->useCase()->execute($this->teacher(), 987654, []);
    }

    public function testNonTeacherIsDenied(): void
    {
        $course = $this->newCourse($this->teacher());

        $this->expectException(NotAuthorizedException::class);
        $this->useCase()->execute($this->learner(), $course->id, []);
    }
}
