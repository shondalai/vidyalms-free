<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Curriculum;

enum CourseState: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
    case Trashed = 'trashed';
}
