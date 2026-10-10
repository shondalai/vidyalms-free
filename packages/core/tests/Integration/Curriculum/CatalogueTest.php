<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Curriculum\GetCatalogue;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetCourseBySlug;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

final class CatalogueTest extends CurriculumTestCase
{
    private function catalogue(): GetCatalogue
    {
        return new GetCatalogue($this->courses);
    }

    public function testOnlyPublishedPublicCoursesAreListed(): void
    {
        $teacher = $this->teacher();

        $visible = $this->publishedCourse($teacher, 'Visible Course');
        $this->newCourse($teacher, 'Draft Course');

        $unlisted = $this->publishedCourse($teacher, 'Unlisted Course');
        $this->updateCourseUseCase()
            ->execute($teacher, $unlisted->id, ['visibility' => 'unlisted']);

        $private = $this->publishedCourse($teacher, 'Private Course');
        $this->updateCourseUseCase()
            ->execute($teacher, $private->id, ['visibility' => 'private']);

        $page = $this->catalogue()->execute(Principal::guest());

        self::assertSame(1, $page->total);
        self::assertCount(1, $page->items);
        self::assertSame($visible->id, $page->items[0]->id);
    }

    public function testTitleAndCategoryFiltersNarrowTheCatalogue(): void
    {
        $teacher = $this->teacher();

        $php = $this->publishedCourse($teacher, 'PHP for Beginners');
        $js = $this->publishedCourse($teacher, 'JavaScript for Beginners');
        $this->publishedCourse($teacher, 'Cooking Masterclass');

        $this->env->courseCategories->add(1, 'PHP');
        $this->env->courseCategories->add(2, 'JavaScript');
        $update = $this->updateCourseUseCase();
        $update->execute($teacher, $php->id, ['category_id' => 1]);
        $update->execute($teacher, $js->id, ['category_id' => 2]);

        $byTitle = $this->catalogue()->execute(Principal::guest(), q: 'Beginners');
        self::assertSame(2, $byTitle->total);

        $byCategory = $this->catalogue()->execute(Principal::guest(), categoryId: 2);
        self::assertSame(1, $byCategory->total);
        self::assertSame($js->id, $byCategory->items[0]->id);

        $byBoth = $this->catalogue()->execute(Principal::guest(), q: 'Beginners', categoryId: 1);
        self::assertSame(1, $byBoth->total);
        self::assertSame($php->id, $byBoth->items[0]->id);
    }

    public function testPaginationReturnsPagesAndAStableTotal(): void
    {
        $teacher = $this->teacher();
        $this->publishedCourse($teacher, 'Alpha');
        $this->publishedCourse($teacher, 'Beta');
        $this->publishedCourse($teacher, 'Gamma');

        $first = $this->catalogue()->execute(Principal::guest(), page: 1, perPage: 2);
        $second = $this->catalogue()->execute(Principal::guest(), page: 2, perPage: 2);

        self::assertSame(3, $first->total);
        self::assertSame(3, $second->total);
        self::assertCount(2, $first->items);
        self::assertCount(1, $second->items);
        self::assertSame(
            ['Alpha', 'Beta', 'Gamma'],
            array_map(
                static fn (Course $course): string => $course->title,
                array_merge($first->items, $second->items),
            ),
        );
    }

    public function testNativeCategoryRestrictionsApplyBeforePaginationAndTotals(): void
    {
        $this->env->courseCategories->add(1, 'Public');
        $this->env->courseCategories->add(2, 'Restricted');
        $public = $this->publishedCourse($this->teacher(), 'Public course');
        $hidden = $this->publishedCourse($this->teacher(), 'A hidden course');
        $this->updateCourseUseCase()->execute($this->teacher(), $public->id, ['category_id' => 1]);
        $this->updateCourseUseCase()->execute($this->teacher(), $hidden->id, ['category_id' => 2]);
        $visibility = new class implements \Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryVisibility {
            public function visibleCategoryIds(int $userId): array { return [1]; }
        };
        $page = (new GetCatalogue($this->courses, $visibility))->execute(Principal::guest(), perPage: 1);
        self::assertSame(1, $page->total);
        self::assertSame($public->id, $page->items[0]->id);
    }

    public function testPerPageIsCappedAtFifty(): void
    {
        $page = $this->catalogue()->execute(Principal::guest(), perPage: 500);

        self::assertSame(50, $page->perPage);

        $floor = $this->catalogue()->execute(Principal::guest(), page: -3, perPage: 0);

        self::assertSame(1, $floor->page);
        self::assertSame(1, $floor->perPage);
    }

    public function testGetCourseBySlugReturnsPublishedCoursesToGuests(): void
    {
        $teacher = $this->teacher();
        $course = $this->publishedCourse($teacher, 'Open Course');

        $found = (new GetCourseBySlug($this->courses, $this->access))
            ->execute(Principal::guest(), 'open-course');

        self::assertSame($course->id, $found->id);
    }

    public function testDraftsAreHiddenFromGuestsAndLearnersButVisibleToStaff(): void
    {
        $teacher = $this->teacher();
        $this->newCourse($teacher, 'Hidden Draft');
        $useCase = new GetCourseBySlug($this->courses, $this->access);

        self::assertSame('Hidden Draft', $useCase->execute($teacher, 'hidden-draft')->title);
        self::assertSame('Hidden Draft', $useCase->execute($this->manager(), 'hidden-draft')->title);

        try {
            $useCase->execute($this->learner(), 'hidden-draft');
            self::fail('Expected an EntityNotFoundException for the learner.');
        } catch (EntityNotFoundException $exception) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(EntityNotFoundException::class);
        $useCase->execute(Principal::guest(), 'hidden-draft');
    }

    public function testUnknownSlugIsNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);
        (new GetCourseBySlug($this->courses, $this->access))->execute(Principal::guest(), 'nope');
    }
}
