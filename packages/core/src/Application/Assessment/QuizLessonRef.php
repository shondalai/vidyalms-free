<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

/**
 * The quiz lesson a quiz is attached to, with the module it sits in, so the
 * Quizzes tab can say where a quiz lives without a second request.
 */
final class QuizLessonRef
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly int $moduleId,
        public readonly string $moduleTitle,
    ) {
    }
}
