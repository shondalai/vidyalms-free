<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;

/**
 * One module with its lessons in editing shape, drafts included.
 */
final class AuthoringSection
{
    /**
     * @param list<AuthoringLesson> $lessons
     */
    public function __construct(
        public readonly Module $module,
        public readonly array $lessons,
    ) {
    }
}
