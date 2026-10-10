<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\Moodle;

/**
 * One activity as the backup manifest lists it. The id is Moodle's course
 * module id, which is the identity the import map keys on: it survives a
 * re-export of the same course, and no two activities on a site share it.
 */
final class MoodleActivity
{
    public function __construct(
        public readonly int $id,
        public readonly int $sectionId,
        public readonly string $modname,
        public readonly ?string $title,
        public readonly string $directory,
    ) {
    }

    /**
     * The activity's own XML entry, "activities/page_12/page.xml".
     */
    public function xmlPath(): string
    {
        return rtrim($this->directory, '/') . '/' . $this->modname . '.xml';
    }
}
