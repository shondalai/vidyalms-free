<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseState;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryState;

final class CreateCourseTest extends CurriculumTestCase
{
    public function testTeacherCreatesADraftCourseWithDerivedSlugAndLeadInstructor(): void
    {
        $teacher = $this->teacher(10);

        $course = $this->createCourseUseCase()->execute($teacher, '  Introduction to PHP!  ');

        self::assertGreaterThan(0, $course->id);
        self::assertSame('Introduction to PHP!', $course->title);
        self::assertSame('introduction-to-php', $course->slug->value);
        self::assertSame(CourseState::Draft, $course->state);
        self::assertSame(10, $course->createdBy);
        self::assertNull($course->publishedAt);

        $row = $this->env->database->selectOne(Query::from('courses')->where('id', '=', $course->id));
        self::assertNotNull($row);
        self::assertSame('draft', $row['state']);
        self::assertSame('2026-01-15 10:00:00', $row['created_at']);
        self::assertSame('2026-01-15 10:00:00', $row['updated_at']);

        $instructor = $this->env->database->selectOne(
            Query::from('course_instructors')->where('course_id', '=', $course->id),
        );
        self::assertNotNull($instructor);
        self::assertEquals(10, $instructor['user_id']);
        self::assertSame('lead', $instructor['role']);
    }

    public function testSlugCollisionsAppendNumericSuffixes(): void
    {
        $teacher = $this->teacher();
        $useCase = $this->createCourseUseCase();

        $first = $useCase->execute($teacher, 'PHP Basics');
        $second = $useCase->execute($teacher, 'PHP Basics');
        $third = $useCase->execute($teacher, 'PHP Basics');

        self::assertSame('php-basics', $first->slug->value);
        self::assertSame('php-basics-2', $second->slug->value);
        self::assertSame('php-basics-3', $third->slug->value);
    }

    public function testExplicitSlugIsUsedAndSuffixedOnCollision(): void
    {
        $teacher = $this->teacher();
        $useCase = $this->createCourseUseCase();

        $first = $useCase->execute($teacher, 'Course One', slug: 'custom-slug');
        $second = $useCase->execute($teacher, 'Course Two', slug: 'custom-slug');

        self::assertSame('custom-slug', $first->slug->value);
        self::assertSame('custom-slug-2', $second->slug->value);
    }

    public function testGuestMayNotCreateACourse(): void
    {
        try {
            $this->createCourseUseCase()->execute(Principal::guest(), 'PHP Basics');
            self::fail('Expected a NotAuthorizedException.');
        } catch (NotAuthorizedException $exception) {
            self::assertSame(0, $this->env->database->count(Query::from('courses')));
        }
    }

    public function testUserWithoutTeachCapabilityMayNotCreateACourse(): void
    {
        $this->env->authorization->grant(30, Capability::GRADE);

        $this->expectException(NotAuthorizedException::class);
        $this->createCourseUseCase()->execute(Principal::user(30), 'PHP Basics');
    }

    public function testEmptyTitleIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->createCourseUseCase()->execute($this->teacher(), '   ');
    }

    public function testInvalidCompletionThresholdIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->createCourseUseCase()->execute($this->teacher(), 'PHP Basics', completionThreshold: 101);
    }

    public function testASelectedCategoryMustExistAndMustNotBeTrashed(): void
    {
        $teacher = $this->teacher();

        try {
            $this->createCourseUseCase()->execute($teacher, 'Missing category', categoryId: 404);
            self::fail('Expected a missing category to be refused.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('category_id', $exception->errors);
        }

        $this->env->courseCategories->add(9, 'Bin', state: CourseCategoryState::Trashed);

        try {
            $this->createCourseUseCase()->execute($teacher, 'Trashed category', categoryId: 9);
            self::fail('Expected a trashed category to be refused.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('category_id', $exception->errors);
        }

        $this->env->courseCategories->add(10, 'Leadership', state: CourseCategoryState::Archived);
        self::assertSame(
            10,
            $this->createCourseUseCase()->execute($teacher, 'Archived but assignable', categoryId: 10)->categoryId,
        );
    }
}
