<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Links;

/** The host owns public permalinks, including subdirectory and non-pretty installations. */
interface CourseLinkPort
{
    public function publicUrl(int $courseId, string $slug): ?string;
}
