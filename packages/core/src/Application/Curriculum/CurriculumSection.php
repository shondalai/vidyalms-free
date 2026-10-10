<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;

/**
 * One module with its lessons in position order. Lessons carry metadata only;
 * content payloads never travel with a curriculum listing.
 */
final class CurriculumSection
{
    /**
     * @param list<Lesson> $lessons
     */
    public function __construct(
        public readonly Module $module,
        public readonly array $lessons,
    ) {
    }
}
