<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Curriculum\AddModule;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ReorderModules;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateModule;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

final class ModuleManagementTest extends CurriculumTestCase
{
    public function testModulesGetSequentialPositions(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);

        $first = $this->newModule($teacher, $course->id, 'Week 1');
        $second = $this->newModule($teacher, $course->id, 'Week 2');

        self::assertSame(1, $first->position);
        self::assertSame(2, $second->position);
        self::assertSame($course->id, $first->courseId);
    }

    public function testAddingAModuleToAnUnknownCourseFails(): void
    {
        $this->expectException(EntityNotFoundException::class);
        (new AddModule($this->modules, $this->access))
            ->execute($this->teacher(), 999, 'Week 1');
    }

    public function testNonTeacherMayNotAddModules(): void
    {
        $course = $this->newCourse($this->teacher());

        $this->expectException(NotAuthorizedException::class);
        (new AddModule($this->modules, $this->access))
            ->execute($this->learner(), $course->id, 'Week 1');
    }

    public function testUpdateModuleChangesTitleSummaryAndState(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $module = $this->newModule($teacher, $course->id, 'Week 1');

        $updated = (new UpdateModule($this->modules, $this->access))
            ->execute($teacher, $module->id, ['title' => 'Week One', 'summary' => 'Basics', 'state' => 'draft']);

        self::assertSame('Week One', $updated->title);
        self::assertSame('Basics', $updated->summary);
        self::assertSame('draft', $updated->state);
        self::assertSame($module->position, $updated->position);

        $stored = $this->modules->byId($module->id);
        self::assertNotNull($stored);
        self::assertSame('Week One', $stored->title);
    }

    public function testUpdateModuleRejectsUnknownFields(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $module = $this->newModule($teacher, $course->id);

        $this->expectException(ValidationException::class);
        (new UpdateModule($this->modules, $this->access))
            ->execute($teacher, $module->id, ['position' => 5]);
    }

    public function testReorderModulesPersistsTheNewOrder(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $a = $this->newModule($teacher, $course->id, 'A');
        $b = $this->newModule($teacher, $course->id, 'B');
        $c = $this->newModule($teacher, $course->id, 'C');

        (new ReorderModules($this->modules, $this->access))
            ->execute($teacher, $course->id, [$c->id, $a->id, $b->id]);

        $ordered = $this->modules->byCourse($course->id);

        self::assertSame(['C', 'A', 'B'], array_map(static fn ($m): string => $m->title, $ordered));
        self::assertSame([1, 2, 3], array_map(static fn ($m): int => $m->position, $ordered));
    }

    public function testReorderRejectsAnIncompleteList(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $a = $this->newModule($teacher, $course->id, 'A');
        $this->newModule($teacher, $course->id, 'B');

        $this->expectException(ValidationException::class);
        (new ReorderModules($this->modules, $this->access))
            ->execute($teacher, $course->id, [$a->id]);
    }

    public function testReorderRejectsForeignAndDuplicateIds(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $other = $this->newCourse($teacher, 'Other Course');
        $a = $this->newModule($teacher, $course->id, 'A');
        $b = $this->newModule($teacher, $course->id, 'B');
        $foreign = $this->newModule($teacher, $other->id, 'Foreign');

        $reorder = new ReorderModules($this->modules, $this->access);

        try {
            $reorder->execute($teacher, $course->id, [$a->id, $foreign->id]);
            self::fail('Expected a ValidationException for the foreign module.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('modules', $exception->errors);
        }

        $this->expectException(ValidationException::class);
        $reorder->execute($teacher, $course->id, [$a->id, $a->id, $b->id]);
    }
}
