<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource;

/**
 * One foreign quiz, normalised onto what CreateQuiz and UpdateQuiz accept.
 *
 * A quiz always belongs to a lesson here, because it does in all three
 * products this shape serves: Shika makes a quiz a lesson format, Guru hangs
 * a quiz off a task, and OSCampus keys its quiz rows on the lesson. That is
 * also the free build's own shape, so nothing is being flattened.
 */
final class QuizDraft
{
    /**
     * @param list<QuestionDraft> $questions
     */
    private function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly ?string $description,
        public readonly int $passMark,
        public readonly ?int $maxAttempts,
        public readonly ?int $timeLimitSeconds,
        public readonly bool $isFinal,
        public readonly array $questions,
    ) {
    }

    /**
     * @param list<QuestionDraft> $questions
     */
    public static function of(
        string $key,
        string $title,
        ?string $description,
        int $passMark,
        ?int $maxAttempts,
        ?int $timeLimitSeconds,
        bool $isFinal,
        array $questions,
    ): self {
        return new self(
            $key,
            $title,
            $description,
            max(0, min(100, $passMark)),
            $maxAttempts !== null && $maxAttempts > 0 ? $maxAttempts : null,
            $timeLimitSeconds !== null && $timeLimitSeconds > 0 ? $timeLimitSeconds : null,
            $isFinal,
            $questions,
        );
    }
}
