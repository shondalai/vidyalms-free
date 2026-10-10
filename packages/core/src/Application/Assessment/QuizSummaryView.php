<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;

/**
 * A quiz as the authoring surface shows it: its settings, where it is
 * attached, and its published questions.
 *
 * The questions carry their authoring definitions, correctness included. That
 * is legitimate in exactly one place, the authoring view of a quiz, and only
 * for a caller who may teach the course; every other surface takes the
 * summary and leaves the questions behind.
 */
final class QuizSummaryView
{
    /**
     * @param list<Question> $questions published questions in position order
     */
    public function __construct(
        public readonly Quiz $quiz,
        public readonly ?QuizLessonRef $lesson,
        public readonly int $questionCount,
        public readonly array $questions = [],
    ) {
    }
}
