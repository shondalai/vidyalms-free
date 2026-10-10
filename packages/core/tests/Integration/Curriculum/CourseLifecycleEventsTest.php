<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Curriculum\ArchiveCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\PublishCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateCourse;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseArchived;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCreated;
use Shondalai\VidyaLMS\Core\Domain\Events\CoursePublished;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseUpdated;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * The course lifecycle as events. The WordPress adapter's permalink shell
 * used to be kept in step by recognising a REST path, which is adapter-side
 * knowledge of the contract and misses every course an importer, a Pro
 * package or WP-CLI creates. These four events are the replacement, and
 * anything else that wants to know (Rewardify, a webhook, a sitemap)
 * subscribes to the same ones.
 */
final class CourseLifecycleEventsTest extends CurriculumTestCase
{
    public function testCreatingACourseEmitsCourseCreatedWithTitleAndSlug(): void
    {
        $teacher = $this->teacher();

        $course = $this->newCourse($teacher, 'Tea Tasting');

        $event = $this->onlyEvent(CourseCreated::class);
        self::assertSame('vidyalms.course.created', $event->eventName());
        self::assertSame($course->id, $event->courseId);
        self::assertSame('Tea Tasting', $event->title);
        self::assertSame('tea-tasting', $event->slug);
        self::assertSame('draft', $event->state);
        self::assertSame($teacher->userId, $event->actorId);
        self::assertSame('course.created:' . $course->id, $event->idempotencyKey());
    }

    public function testPublishingAndArchivingEmitTheirTransitionsOnce(): void
    {
        $teacher = $this->teacher();
        $course = $this->publishedCourse($teacher, 'Tea Tasting');

        $published = $this->onlyEvent(CoursePublished::class);
        self::assertSame($course->id, $published->courseId);
        self::assertSame('published', $published->payload()['state']);

        // Publishing a published course is not an occurrence.
        (new PublishCourse(
            $this->courses,
            $this->lessons,
            $this->access,
            $this->env->clock,
            $this->events,
        ))->execute($teacher, $course->id);

        self::assertCount(1, $this->eventsOf(CoursePublished::class));

        $archiver = new ArchiveCourse($this->courses, $this->access, $this->events, $this->env->clock);
        $archiver->execute($teacher, $course->id);
        $archiver->execute($teacher, $course->id);

        $archived = $this->onlyEvent(CourseArchived::class);
        self::assertSame($course->id, $archived->courseId);
        self::assertSame('archived', $archived->payload()['state']);
    }

    public function testUpdatingNamesTheFieldsThatActuallyMoved(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher, 'Tea Tasting');
        $update = $this->updateCourseUseCase();

        $update->execute($teacher, $course->id, ['title' => 'Tea Tasting', 'summary' => 'Steep well.']);

        $event = $this->onlyEvent(CourseUpdated::class);
        self::assertSame(['summary'], $event->changed, 'a title set to what it already was did not change');
        self::assertSame('tea-tasting', $event->slug);
    }

    public function testAnUpdateThatChangesNothingEmitsNothing(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher, 'Tea Tasting');
        $update = $this->updateCourseUseCase();

        $update->execute($teacher, $course->id, ['title' => 'Tea Tasting', 'featured' => false]);

        self::assertSame([], $this->eventsOf(CourseUpdated::class));
    }

    public function testASlugChangeIsNamedSoAPermalinkListenerCanActOnItAlone(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher, 'Tea Tasting');
        $update = $this->updateCourseUseCase();

        $update->execute($teacher, $course->id, ['slug' => 'oolong-tasting']);

        $event = $this->onlyEvent(CourseUpdated::class);
        self::assertSame(['slug'], $event->changed);
        self::assertSame('oolong-tasting', $event->payload()['slug']);
    }

    /**
     * @template T of DomainEvent
     *
     * @param class-string<T> $eventClass
     *
     * @return T
     */
    private function onlyEvent(string $eventClass): DomainEvent
    {
        $found = $this->eventsOf($eventClass);

        self::assertCount(1, $found, $eventClass . ' should have been emitted exactly once');

        return $found[0];
    }

    /**
     * @template T of DomainEvent
     *
     * @param class-string<T> $eventClass
     *
     * @return list<T>
     */
    private function eventsOf(string $eventClass): array
    {
        $found = [];

        foreach ($this->env->eventBridge->published as $event) {
            if ($event instanceof $eventClass) {
                $found[] = $event;
            }
        }

        return $found;
    }
}
