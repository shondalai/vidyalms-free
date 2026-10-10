<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategory;
use Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryPort;

final class GetCourseCategory
{
    public function __construct(
        private readonly CourseCategoryPort $categories,
        private readonly AccessGuard $guard,
    ) {
    }

    public function execute(Principal $principal, int $id): CourseCategory
    {
        $this->guard->requireAny($principal, Capability::TEACH, Capability::MANAGE);

        if ($id < 1) {
            throw ValidationException::forField('id', 'Must be 1 or more.');
        }

        return $this->categories->findById($id)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Course category', $id);
    }
}
