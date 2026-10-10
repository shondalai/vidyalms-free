<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

/**
 * One assigned instructor of a course. The name comes from the users port
 * and is null when the CMS account is gone: the row survives the account, so
 * a course never loses its instructor list to a deleted user.
 */
final class Instructor
{
    public function __construct(
        public readonly int $userId,
        public readonly ?string $name,
        public readonly string $role,
    ) {
    }
}
