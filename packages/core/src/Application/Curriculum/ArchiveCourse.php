<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseState;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseArchived;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

final class ArchiveCourse
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly CourseAccess $access,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
    ) {
    }

    public function execute(Principal $principal, int $courseId): Course
    {
        $course = $this->access->require($principal, $courseId, Capability::TEACH);

        if ($course->state === CourseState::Archived) {
            return $course;
        }

        $archived = $course->archive();
        $this->courses->update($archived);

        $this->events->dispatch(new CourseArchived(
            $this->clock->now(),
            $archived->id,
            $archived->title,
            $archived->slug->value,
            $principal->userId,
        ));

        return $archived;
    }
}
