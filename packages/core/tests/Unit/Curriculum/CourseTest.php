<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Curriculum;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CompletionRule;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseState;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseVisibility;
use Shondalai\VidyaLMS\Core\Domain\Shared\Slug;

final class CourseTest extends TestCase
{
    public function testEnumValuesMatchTheNeutralSchemaDefaults(): void
    {
        self::assertSame('draft', CourseState::Draft->value);
        self::assertSame('published', CourseState::Published->value);
        self::assertSame('archived', CourseState::Archived->value);
        self::assertSame('public', CourseVisibility::Public->value);
        self::assertSame('private', CourseVisibility::Private->value);
        self::assertSame('unlisted', CourseVisibility::Unlisted->value);
        self::assertSame('all_lessons', CompletionRule::AllLessons->value);
        self::assertSame('percentage', CompletionRule::Percentage->value);
        self::assertSame('pass_final', CompletionRule::PassFinal->value);
    }

    public function testPublishSetsStateAndTimestampWithoutTouchingOtherFields(): void
    {
        $course = $this->draftCourse();
        $at = new \DateTimeImmutable('2026-02-01 09:00:00', new \DateTimeZone('UTC'));

        $published = $course->publish($at);

        self::assertSame(CourseState::Published, $published->state);
        self::assertSame($at, $published->publishedAt);
        self::assertSame($course->title, $published->title);
        self::assertSame($course->slug->value, $published->slug->value);
        self::assertSame(CourseState::Draft, $course->state);
    }

    public function testArchiveKeepsThePublishedTimestamp(): void
    {
        $at = new \DateTimeImmutable('2026-02-01 09:00:00', new \DateTimeZone('UTC'));
        $archived = $this->draftCourse()->publish($at)->archive();

        self::assertSame(CourseState::Archived, $archived->state);
        self::assertSame($at, $archived->publishedAt);
    }

    public function testWithIdReplacesOnlyTheId(): void
    {
        $course = $this->draftCourse()->withId(99);

        self::assertSame(99, $course->id);
        self::assertSame('PHP Basics', $course->title);
    }

    public function testPublicListingRequiresPublishedStateAndPublicVisibility(): void
    {
        $draft = $this->draftCourse();
        $published = $draft->publish(new \DateTimeImmutable('2026-02-01 09:00:00', new \DateTimeZone('UTC')));

        self::assertFalse($draft->isPubliclyListed());
        self::assertTrue($published->isPubliclyListed());

        $unlisted = new Course(
            id: 1,
            title: 'PHP Basics',
            slug: Slug::fromString('php-basics'),
            summary: null,
            description: null,
            image: null,
            categoryId: null,
            level: null,
            language: null,
            visibility: CourseVisibility::Unlisted,
            state: CourseState::Published,
            priceMode: 'free',
            completionRule: CompletionRule::AllLessons,
            completionThreshold: null,
            featured: false,
            createdBy: 10,
            publishedAt: null,
        );

        self::assertTrue($unlisted->isPublished());
        self::assertFalse($unlisted->isPubliclyListed());
    }

    private function draftCourse(): Course
    {
        return new Course(
            id: 1,
            title: 'PHP Basics',
            slug: Slug::fromString('php-basics'),
            summary: 'Learn PHP',
            description: null,
            image: null,
            categoryId: 3,
            level: 'beginner',
            language: 'en-GB',
            visibility: CourseVisibility::Public,
            state: CourseState::Draft,
            priceMode: 'free',
            completionRule: CompletionRule::AllLessons,
            completionThreshold: null,
            featured: false,
            createdBy: 10,
            publishedAt: null,
        );
    }
}
