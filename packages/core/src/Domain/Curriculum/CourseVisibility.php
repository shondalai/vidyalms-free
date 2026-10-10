<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Curriculum;

enum CourseVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case Unlisted = 'unlisted';
}
