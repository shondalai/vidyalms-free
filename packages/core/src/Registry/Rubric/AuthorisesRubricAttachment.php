<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Rubric;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/** Permission to reuse a saved rubric in a course, or a shared bank. */
interface AuthorisesRubricAttachment
{
    public function requireAttachment(Principal $principal, int $versionId, ?int $courseId): void;
}
