<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

final class GetCourseBySlug
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly CourseAccess $access,
    ) {
    }

    public function execute(Principal $principal, string $slug): Course
    {
        $course = $this->courses->bySlug($slug);

        // Private and unpublished courses do not disclose their existence
        // to readers without access.
        if ($course === null || !$this->access->mayRead($principal, $course)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Course', $slug);
        }

        return $course;
    }
}
