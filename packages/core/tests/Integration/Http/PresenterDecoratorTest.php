<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Http\Presenter\CurriculumPresenter;
use Shondalai\VidyaLMS\Core\Tests\Support\Pro\SeatsOnCourses;

/**
 * A package adding a field to a management response.
 *
 * The presenter used to be one final class with a private constructor and
 * twenty-eight public static methods, called by name from forty-two handlers:
 * not decoratable, not subclassable, not injectable. The route seam is
 * additive only, so replacing the route was no way round it either, which
 * made "seats_used on the course row", the first thing Pro needs, an edit to
 * a free build file.
 */
final class PresenterDecoratorTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testTheFreeBuildEmitsExactlyTheContractsFields(): void
    {
        $response = $this->fixture->call('GET', '/manage/courses', $this->fixture->teacher());

        self::assertNotNull($response->data);
        self::assertArrayNotHasKey('seats_used', $response->data['items'][0]);
    }

    public function testAPackageAddsAFieldWithoutTouchingTheFreeBuild(): void
    {
        $this->fixture->kernel->presenters->decorate(new SeatsOnCourses());

        $list = $this->fixture->call('GET', '/manage/courses', $this->fixture->teacher());
        self::assertNotNull($list->data);
        self::assertSame(7, $list->data['items'][0]['seats_used']);

        // The detail shape is built on the list shape, so one decorator
        // reaches both, which is why it is keyed by shape and not by route.
        $detail = $this->fixture->call(
            'GET',
            '/manage/courses/' . $this->fixture->courseId,
            $this->fixture->teacher(),
        );
        self::assertNotNull($detail->data);
        self::assertSame(7, $detail->data['seats_used']);
    }

    public function testADecoratorSeesOnlyTheShapesItClaims(): void
    {
        $this->fixture->kernel->presenters->decorate(new SeatsOnCourses());

        $curriculum = $this->fixture->call(
            'GET',
            '/manage/courses/' . $this->fixture->courseId . '/curriculum',
            $this->fixture->teacher(),
        );

        self::assertNotNull($curriculum->data);
        self::assertArrayNotHasKey('seats_used', $curriculum->data['modules'][0]);
    }

    public function testDecoratorsRunInOrderAndEachSeesTheLast(): void
    {
        $registry = $this->fixture->kernel->presenters;

        self::assertFalse($registry->isExtended());

        $registry->decorate(new class implements \Shondalai\VidyaLMS\Core\Http\Presenter\ShapeDecorator {
            public function supports(string $shape): bool
            {
                return $shape === CurriculumPresenter::SHAPE_MANAGED_COURSE;
            }

            public function decorate(array $emitted, object $source): array
            {
                return $emitted + ['trail' => 'first'];
            }
        });

        $registry->decorate(new class implements \Shondalai\VidyaLMS\Core\Http\Presenter\ShapeDecorator {
            public function supports(string $shape): bool
            {
                return $shape === CurriculumPresenter::SHAPE_MANAGED_COURSE;
            }

            public function decorate(array $emitted, object $source): array
            {
                $emitted['trail'] .= ' then second';

                return $emitted;
            }
        });

        self::assertTrue($registry->isExtended());

        $list = $this->fixture->call('GET', '/manage/courses', $this->fixture->teacher());

        self::assertNotNull($list->data);
        self::assertSame('first then second', $list->data['items'][0]['trail']);
    }
}
