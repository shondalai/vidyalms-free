<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Shared;

/**
 * An event that belongs on the activity log.
 *
 * The projector used to be a chain of instanceof branches, one per event,
 * each existing only to say which property of that event was the subject
 * user, the course, the lesson and the enrolment. The event knows that about
 * itself, so it answers it, and a package that ships an event of its own is
 * projected by implementing one method rather than by editing a free build
 * class it does not own.
 *
 * Opting out is not implementing this. CourseUpdated does not: a course saved
 * eleven times while it is written is eleven rows nobody did anything on.
 */
interface ActivityProjectable
{
    public function activityRef(): ActivityRef;
}
