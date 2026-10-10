<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Curriculum;

/**
 * A course with the two row timestamps the management screens show. The
 * Course entity carries no created_at or updated_at, because no rule of the
 * domain reads them; a list that sorts on "last edited" does, so they travel
 * beside the entity rather than inside it.
 */
final class CourseRecord
{
    public function __construct(
        public readonly Course $course,
        public readonly \DateTimeImmutable $createdAt,
        public readonly \DateTimeImmutable $updatedAt,
    ) {
    }
}
