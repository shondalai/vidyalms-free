<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft;

/**
 * One thing inside a section: a lesson of a free lesson type, or a quiz with
 * its questions.
 *
 * A quiz is an item rather than a separate collection because all three
 * WordPress products place a quiz in the curriculum exactly where a lesson
 * goes, and the free build does the same thing: a quiz is hosted by a lesson
 * of the "quiz" type. Keeping them in one ordered list is what makes the
 * imported curriculum come out in the source's own order rather than with
 * every quiz swept to the end.
 */
final class ItemDraft
{
    public const KIND_LESSON = 'lesson';
    public const KIND_QUIZ = 'quiz';

    /**
     * @param string                $sourceId        the product's own identity for this item
     * @param string                $kind            one of the KIND_* constants
     * @param string                $title           already trimmed, never empty
     * @param string                $lessonType      a registered free lesson type key
     * @param array<string, mixed>  $content         the content that lesson type validates
     * @param list<QuestionDraft>   $questions       empty for a lesson
     * @param int|null              $durationSeconds null when the product recorded none
     * @param int                   $passMark        quiz only, a whole percentage
     * @param int|null              $maxAttempts     quiz only, null meaning unlimited
     * @param int|null              $timeLimit       quiz only, in seconds
     */
    private function __construct(
        public readonly string $sourceId,
        public readonly string $kind,
        public readonly string $title,
        public readonly string $lessonType,
        public readonly array $content,
        public readonly array $questions,
        public readonly ?int $durationSeconds,
        public readonly int $passMark,
        public readonly ?int $maxAttempts,
        public readonly ?int $timeLimit,
    ) {
    }

    /**
     * @param array<string, mixed> $content
     */
    public static function lesson(
        string $sourceId,
        string $title,
        string $lessonType,
        array $content,
        ?int $durationSeconds = null,
    ): self {
        return new self($sourceId, self::KIND_LESSON, $title, $lessonType, $content, [], $durationSeconds, 0, null, null);
    }

    /**
     * @param list<QuestionDraft> $questions
     */
    public static function quiz(
        string $sourceId,
        string $title,
        array $questions,
        int $passMark = 0,
        ?int $maxAttempts = null,
        ?int $timeLimit = null,
    ): self {
        return new self(
            $sourceId,
            self::KIND_QUIZ,
            $title,
            'quiz',
            [],
            $questions,
            null,
            $passMark,
            $maxAttempts,
            $timeLimit,
        );
    }

    public function isQuiz(): bool
    {
        return $this->kind === self::KIND_QUIZ;
    }
}
