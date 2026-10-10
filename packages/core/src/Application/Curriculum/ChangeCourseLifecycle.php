<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseState;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseUpdated;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/** Recoverable deletion preserves curriculum, enrolments, grades and credentials. */
final class ChangeCourseLifecycle
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly CourseAccess $access,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
    ) {
    }

    public function execute(Principal $principal, int $courseId, string $action): void
    {
        $course = $this->access->requireIncludingTrashed($principal, $courseId, Capability::TEACH);
        $next = match ($action) {
            'trash' => $course->trash(),
            'restore' => $course->state === CourseState::Trashed ? $course->unpublish() : $course,
            'unpublish' => $course->state === CourseState::Trashed
                ? throw new ConflictException('Restore this course from Trash before editing it.')
                : $course->unpublish(),
            default => throw new \LogicException('Unknown course lifecycle action.'),
        };

        if ($next->state === $course->state) {
            return;
        }

        $this->courses->update($next);
        $this->events->dispatch(new CourseUpdated(
            $this->clock->now(),
            $next->id,
            $next->title,
            $next->slug->value,
            $next->state->value,
            ['state'],
            $principal->userId,
        ));
    }
}
