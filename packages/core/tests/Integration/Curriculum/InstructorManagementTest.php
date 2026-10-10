<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Curriculum\AssignInstructor;
use Shondalai\VidyaLMS\Core\Application\Curriculum\RemoveInstructor;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class InstructorManagementTest extends CurriculumTestCase
{
    private function assign(): AssignInstructor
    {
        return new AssignInstructor($this->courses, $this->guard);
    }

    private function remove(): RemoveInstructor
    {
        return new RemoveInstructor($this->courses, $this->guard);
    }

    public function testManagerAssignsAnInstructorWithARole(): void
    {
        $course = $this->newCourse($this->teacher(10));

        $this->assign()->execute($this->manager(), $course->id, 55, 'co');

        $row = $this->env->database->selectOne(
            Query::from('course_instructors')
                ->where('course_id', '=', $course->id)
                ->where('user_id', '=', 55),
        );
        self::assertNotNull($row);
        self::assertSame('co', $row['role']);
        self::assertTrue($this->courses->isInstructor($course->id, 55));
    }

    public function testAssigningTheSameUserTwiceConflicts(): void
    {
        $course = $this->newCourse($this->teacher(10));

        // The creator is already recorded as the lead instructor.
        $this->expectException(ConflictException::class);
        $this->assign()->execute($this->manager(), $course->id, 10);
    }

    public function testTeachAloneIsNotEnoughToAssignInstructors(): void
    {
        $teacher = $this->teacher(10);
        $course = $this->newCourse($teacher);

        $this->expectException(NotAuthorizedException::class);
        $this->assign()->execute($teacher, $course->id, 55);
    }

    public function testGuestMayNotAssignInstructors(): void
    {
        $course = $this->newCourse($this->teacher());

        $this->expectException(NotAuthorizedException::class);
        $this->assign()->execute(Principal::guest(), $course->id, 55);
    }

    public function testAssigningToAnUnknownCourseFails(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->assign()->execute($this->manager(), 999, 55);
    }

    public function testRemovingAnInstructorDeletesTheRow(): void
    {
        $manager = $this->manager();
        $course = $this->newCourse($this->teacher(10));
        $this->assign()->execute($manager, $course->id, 55, 'co');

        $this->remove()->execute($manager, $course->id, 55);

        self::assertFalse($this->courses->isInstructor($course->id, 55));
    }

    public function testRemovingANonInstructorIsNotFound(): void
    {
        $course = $this->newCourse($this->teacher(10));

        $this->expectException(EntityNotFoundException::class);
        $this->remove()->execute($this->manager(), $course->id, 777);
    }
}
