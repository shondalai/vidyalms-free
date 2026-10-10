<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Assessment\QuizSummaryView;
use Shondalai\VidyaLMS\Core\Domain\Assignments\Assignment;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;

/**
 * A lesson as its editor shows it: the stored content payload verbatim,
 * private storage paths included, because the author sends the same payload
 * back on update. No signed URL is minted here, and this shape never reaches
 * a learner.
 *
 * contentAvailable is false when no provider is registered for the type. The
 * content is stored and still travels, but nothing on the site can validate
 * or present it, so the editor shows a placeholder rather than pretending.
 */
final class AuthoringLesson
{
    /**
     * @param array<string, mixed> $content
     */
    public function __construct(
        public readonly Lesson $lesson,
        public readonly array $content,
        public readonly bool $contentAvailable,
        public readonly ?QuizSummaryView $quiz,
        public readonly ?Assignment $assignment,
    ) {
    }
}
