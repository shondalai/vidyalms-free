<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Domain\Platform\ActivityEntry;
use Shondalai\VidyaLMS\Core\Domain\Platform\ActivityLogRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ActivityProjectable;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * Projects domain events into the activity_log table so the learner timeline
 * is a plain query.
 *
 * There is no list of events here and no branch per event. An event that
 * belongs on the timeline implements ActivityProjectable and answers who and
 * what it is about; everything else passes through untouched. That is what
 * lets a package add a projected event of its own without editing this class,
 * and what stops this class from having to know which property of each event
 * is the subject user.
 *
 * The course lifecycle is projected because "who published this and when" is
 * a question an administrator asks of a live site, and the log is where every
 * other answer of that shape already lives. CourseUpdated is not, and the
 * reason is in its own docblock: a course is saved a dozen times while it is
 * written, and a dozen rows nobody did anything on is how a timeline becomes
 * unreadable.
 */
final class ActivityLogProjector
{
    public function __construct(
        private readonly ActivityLogRepository $activityLog,
    ) {
    }

    public function subscribe(EventBus $bus): void
    {
        $bus->subscribe(ActivityProjectable::class, $this);
    }

    public function __invoke(DomainEvent $event): void
    {
        if (!$event instanceof ActivityProjectable) {
            return;
        }

        $ref = $event->activityRef();

        $this->activityLog->append(new ActivityEntry(
            $ref->userId,
            $event->eventName(),
            $event->occurredAt,
            $ref->courseId,
            $ref->lessonId,
            $ref->enrolmentId,
            $event->payload(),
            $event->idempotencyKey(),
        ));
    }
}
