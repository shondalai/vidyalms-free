<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateCourse;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseVisibility;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryState;

final class UpdateCourseTest extends CurriculumTestCase
{
    private function useCase(): UpdateCourse
    {
        return $this->updateCourseUseCase();
    }

    public function testAppliesOnlyTheGivenChanges(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $this->env->courseCategories->add(7, 'Development');

        $updated = $this->useCase()->execute($teacher, $course->id, [
            'title' => 'PHP Fundamentals',
            'summary' => 'A fresh summary',
            'visibility' => 'unlisted',
            'featured' => true,
            'category_id' => 7,
        ]);

        self::assertSame('PHP Fundamentals', $updated->title);
        self::assertSame('A fresh summary', $updated->summary);
        self::assertSame(CourseVisibility::Unlisted, $updated->visibility);
        self::assertTrue($updated->featured);
        self::assertSame(7, $updated->categoryId);
        self::assertSame($course->slug->value, $updated->slug->value);

        $row = $this->env->database->selectOne(Query::from('courses')->where('id', '=', $course->id));
        self::assertNotNull($row);
        self::assertSame('PHP Fundamentals', $row['title']);
        self::assertSame('unlisted', $row['visibility']);
        self::assertEquals(1, $row['featured']);
    }

    public function testSummaryCanBeCleared(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $this->useCase()->execute($teacher, $course->id, ['summary' => 'Something']);

        $updated = $this->useCase()->execute($teacher, $course->id, ['summary' => null]);

        self::assertNull($updated->summary);
    }

    public function testChangingTheSlugToAnotherCoursesSlugConflicts(): void
    {
        $teacher = $this->teacher();
        $this->newCourse($teacher, 'Course A');
        $courseB = $this->newCourse($teacher, 'Course B');

        $this->expectException(ConflictException::class);
        $this->useCase()->execute($teacher, $courseB->id, ['slug' => 'course-a']);
    }

    public function testKeepingTheOwnSlugIsNotAConflict(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher, 'Course A');

        $updated = $this->useCase()->execute($teacher, $course->id, ['slug' => 'course-a', 'title' => 'Course A2']);

        self::assertSame('course-a', $updated->slug->value);
        self::assertSame('Course A2', $updated->title);
    }

    public function testUnknownCourseIsNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->useCase()->execute($this->teacher(), 999, ['title' => 'X']);
    }

    public function testStateCannotBeChangedThroughUpdate(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);

        $this->expectException(ValidationException::class);
        $this->useCase()->execute($teacher, $course->id, ['state' => 'published']);
    }

    public function testNonTeacherIsDenied(): void
    {
        $course = $this->newCourse($this->teacher());

        $this->expectException(NotAuthorizedException::class);
        $this->useCase()->execute($this->learner(), $course->id, ['title' => 'Hijacked']);
    }

    public function testNewCategoryAssignmentsRejectMissingAndTrashedRows(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);

        foreach ([404, 405] as $categoryId) {
            if ($categoryId === 405) {
                $this->env->courseCategories->add(405, 'Trash', state: CourseCategoryState::Trashed);
            }

            try {
                $this->useCase()->execute($teacher, $course->id, ['category_id' => $categoryId]);
                self::fail('Expected the new category assignment to be refused.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('category_id', $exception->errors);
            }
        }
    }

    public function testAnAlreadyStoredDanglingCategoryRemainsReadableAndSaveable(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $this->env->database->update('courses', ['category_id' => 909], ['id' => $course->id]);

        $updated = $this->useCase()->execute($teacher, $course->id, [
            'title' => 'Still editable',
            // A complete settings form may submit its unchanged missing id.
            'category_id' => 909,
        ]);

        self::assertSame(909, $updated->categoryId);
        self::assertSame(909, $this->courses->byId($course->id)?->categoryId);
    }
}
