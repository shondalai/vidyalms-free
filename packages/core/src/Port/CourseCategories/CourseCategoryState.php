<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\CourseCategories;

/** The four host-neutral lifecycle states a native category may expose. */
enum CourseCategoryState: string
{
    case Published = 'published';
    case Unpublished = 'unpublished';
    case Archived = 'archived';
    case Trashed = 'trashed';
}
