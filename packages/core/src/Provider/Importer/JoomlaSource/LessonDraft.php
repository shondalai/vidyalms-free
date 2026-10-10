<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource;

/**
 * One foreign lesson, either translated into a free lesson type or refused
 * with the reason, exactly as QuestionDraft is.
 *
 * A quiz lesson carries its quiz, because the free build needs the quiz to
 * exist before the lesson can point at it and the writer is what knows the
 * order. The reader's job is to say what is there, not when to write it.
 */
final class LessonDraft
{
    /**
     * @param array<string, mixed> $content
     */
    private function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $sourceType,
        public readonly string $type,
        public readonly array $content,
        public readonly ?int $durationSeconds,
        public readonly bool $freePreview,
        public readonly ?QuizDraft $quiz,
        public readonly ?string $refusal,
        public readonly ?string $note,
    ) {
    }

    /**
     * @param array<string, mixed> $content
     */
    public static function of(
        string $key,
        string $title,
        string $sourceType,
        string $type,
        array $content,
        ?int $durationSeconds = null,
        bool $freePreview = false,
        ?QuizDraft $quiz = null,
        ?string $note = null,
    ): self {
        return new self(
            $key,
            $title,
            $sourceType,
            $type,
            $content,
            $durationSeconds !== null && $durationSeconds > 0 ? $durationSeconds : null,
            $freePreview,
            $quiz,
            null,
            $note,
        );
    }

    public static function refused(string $key, string $title, string $sourceType, string $reason): self
    {
        return new self($key, $title, $sourceType, '', [], null, false, null, $reason, null);
    }

    public function isRefused(): bool
    {
        return $this->refusal !== null;
    }
}
