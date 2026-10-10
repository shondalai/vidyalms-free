<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Shondalai\VidyaLMS\Core\Port\Links\CourseLinkPort;

/** A public component URL, independent of the administrator or scheduled-task request. */
final class JoomlaCourseLinks implements CourseLinkPort
{
    public function __construct(private readonly string $siteRoot)
    {
    }

    public function publicUrl(int $courseId, string $slug): ?string
    {
        if ($courseId < 1 || $slug === '' || $this->siteRoot === '') {
            return null;
        }
        return rtrim($this->siteRoot, '/') . '/index.php?option=com_vidyalms&view=course&slug=' . rawurlencode($slug);
    }
}
