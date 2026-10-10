<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

final class AssignInstructor
{
    public function __construct(
        private readonly CourseRepository $courses,
        private readonly AccessGuard $guard,
    ) {
    }

    public function execute(Principal $principal, int $courseId, int $userId, string $role = 'lead'): void
    {
        $this->guard->require($principal, Capability::MANAGE);

        if ($this->courses->byId($courseId) === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Course', $courseId);
        }

        if ($userId <= 0) {
            throw ValidationException::forField('user_id', 'A positive user id is required.');
        }

        $role = trim($role);

        if ($role === '' || \strlen($role) > 20) {
            throw ValidationException::forField('role', 'The role must be 1 to 20 characters long.');
        }

        if ($this->courses->isInstructor($courseId, $userId)) {
            throw new ConflictException('This user is already an instructor of the course.');
        }

        $this->courses->assignInstructor($courseId, $userId, $role);
    }
}
