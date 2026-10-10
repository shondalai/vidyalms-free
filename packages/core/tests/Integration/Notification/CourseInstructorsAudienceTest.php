<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Provider\Notification\Audience\CourseInstructorsAudience;
use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The one shipped resolver a third-party audience author copies.
 *
 * It declared FiltersByVisibility, which is the promise that lets the
 * fan-out use it inside a scoped run, and then never read the scope. Every
 * author who copied it copied the marker and the omission together.
 */
final class CourseInstructorsAudienceTest extends TestCase
{
    private const COURSE = 41;
    private const OTHER_COURSE = 42;

    private TestPorts $env;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();

        foreach ([[self::COURSE, 10], [self::COURSE, 11], [self::OTHER_COURSE, 12]] as [$courseId, $userId]) {
            $this->env->database->insert('course_instructors', [
                'course_id' => $courseId,
                'user_id' => $userId,
                'role' => 'lead',
                'created_at' => $this->env->clock->now()->format('Y-m-d H:i:s'),
                'updated_at' => $this->env->clock->now()->format('Y-m-d H:i:s'),
            ]);
        }
    }

    public function testTheDeclaredCapabilityIsStillDeclared(): void
    {
        self::assertInstanceOf(FiltersByVisibility::class, new CourseInstructorsAudience());
    }

    public function testAnUnscopedRunReachesTheWholeTeachingTeam(): void
    {
        self::assertSame([10, 11], $this->resolve(null));
        self::assertSame([10, 11], $this->resolve(VisibilityScope::site()));
    }

    public function testACourseScopeNamingAnotherCourseNarrowsItToNobody(): void
    {
        self::assertSame([], $this->resolve(VisibilityScope::forCourse(self::OTHER_COURSE)));
    }

    public function testACourseScopeNamingThisCourseLeavesItWhole(): void
    {
        self::assertSame([10, 11], $this->resolve(VisibilityScope::forCourse(self::COURSE)));
    }

    /**
     * A scope this resolver cannot express is a scope it must not widen, so
     * the answer is nobody rather than everybody. It is the same direction
     * the registry publishes for a missing audience.
     */
    public function testAScopeItCannotExpressResolvesToNobody(): void
    {
        self::assertSame([], $this->resolve(VisibilityScope::of(VisibilityScope::TYPE_COHORT, 5)));
        self::assertSame([], $this->resolve(VisibilityScope::of(VisibilityScope::TYPE_GROUP, 5)));
    }

    /**
     * @return list<int>
     */
    private function resolve(?VisibilityScope $scope): array
    {
        $context = AudienceContext::create(
            $this->env->database,
            $this->env->authorization,
            'course',
            self::COURSE,
            self::COURSE,
            null,
            [],
            $scope,
        );

        return array_values(iterator_to_array((new CourseInstructorsAudience())->resolve($context), false));
    }
}
