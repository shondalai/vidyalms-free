<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;

final class TopCourse
{
    public function __construct(
        public readonly Course $course,
        public readonly int $activeCount,
        public readonly int $enrolledCount,
        public readonly int $completedCount,
        public readonly int $lessonCount,
    ) {
    }
}
