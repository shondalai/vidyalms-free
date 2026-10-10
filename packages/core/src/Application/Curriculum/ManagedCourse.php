<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseCounts;

/**
 * One row of the management course list: the stored course, the two row
 * timestamps, the counts computed on read and the instructors with their
 * names resolved.
 */
final class ManagedCourse
{
    /**
     * @param list<Instructor> $instructors
     */
    public function __construct(
        public readonly Course $course,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
        public readonly CourseCounts $counts,
        public readonly array $instructors,
    ) {
    }
}
