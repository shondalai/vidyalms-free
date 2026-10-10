<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;

final class RemoveInstructor
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly AccessGuard $guard,
    ) {
    }

    public function execute(Principal $principal, int $courseId, int $userId): void
    {
        $this->guard->require($principal, Capability::MANAGE);

        if ($this->courses->byId($courseId) === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Course', $courseId);
        }

        if (!$this->courses->removeInstructor($courseId, $userId)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Instructor', $userId);
        }
    }
}
