<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Assessment\QuizPublicationGate;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\LessonRepository;
use Shondalai\VidyaLMS\Core\Domain\Events\CoursePublished;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

final class PublishCourse
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly LessonRepository $lessons,
        private readonly CourseAccess $access,
        private readonly ClockPort $clock,
        private readonly EventBus $events,
        /**
         * Optional and last so an adapter built before the gate existed
         * keeps constructing this use case; without it a course publishes
         * exactly as it always did.
         */
        private readonly ?QuizPublicationGate $publication = null,
    ) {
    }

    public function execute(Principal $principal, int $courseId): Course
    {
        $course = $this->access->require($principal, $courseId, Capability::TEACH);

        if ($course->isPublished()) {
            return $course;
        }

        if ($this->lessons->publishedCountByCourse($courseId) === 0) {
            throw new ConflictException('A course needs at least one published lesson before it can be published.');
        }

        // **The publication checks run here.** This is the draft to ready
        // transition for everything in the course: the moment its quizzes
        // stop being drafts and start being sat. Every blocking check the
        // design lists is refused with the item it names, so an author is
        // told before a learner is. Warnings do not block, which is the
        // whole point of the three levels.
        $this->publication?->requireCoursePublishable($courseId);

        $now = $this->clock->now();
        $published = $course->publish($now);
        $this->courses->update($published);

        $this->events->dispatch(new CoursePublished(
            $now,
            $published->id,
            $published->title,
            $published->slug->value,
            $principal->userId,
        ));

        return $published;
    }
}
