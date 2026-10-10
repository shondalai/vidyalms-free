<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

/**
 * How many items a course's gradebook queue is holding right now.
 *
 * The course workbench shows the number on its Gradebook tab, and the
 * gradebook itself is assembled in another context. This is the whole of
 * what Curriculum needs to know about that, which keeps the dependency one
 * method wide and pointed inward.
 */
interface GradingBacklog
{
    public function pendingCountFor(int $courseId): int;
}
