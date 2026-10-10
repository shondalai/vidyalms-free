<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\Moodle;

/**
 * One Moodle course section, which becomes one module.
 *
 * The title is nullable because Moodle stores no name for a section left at
 * its default heading ("Topic 3"); the importer, not the archive, decides
 * what to call it, so the fallback can be translated.
 */
final class MoodleSection
{
    /**
     * @param list<int> $sequence course module ids in the order Moodle showed them
     */
    public function __construct(
        public readonly int $id,
        public readonly int $number,
        public readonly ?string $title,
        public readonly ?string $summary,
        public readonly array $sequence,
    ) {
    }
}
