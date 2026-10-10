<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\WordPress\Port;

defined('ABSPATH') or exit;

use Shondalai\VidyaLMS\Core\Port\Links\CourseLinkPort;
use Shondalai\VidyaLMS\WordPress\Content\CourseCpt;

/** Reuses the canonical host permalink rather than inventing a pretty route. */
final class WpCourseLinks implements CourseLinkPort
{
    public function publicUrl(int $courseId, string $slug): ?string
    {
        return $courseId < 1 || $slug === '' ? null : CourseCpt::permalink($courseId, $slug);
    }
}
