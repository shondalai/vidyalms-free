<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

/**
 * QTI's qti-item-session-control, names and defaults adopted verbatim.
 *
 * Verbatim is the point: a migrating author meets no surprise, and there is
 * no design argument to have about what "allow_review" should mean. The
 * quiz carries the defaults and a structural node may narrow them, which is
 * why this is a value object rather than seven scattered booleans.
 *
 * The columns carry an "item_" prefix because quizzes.max_attempts already
 * means attempts at the quiz, and QTI's max_attempts means tries at an item.
 * They are real columns rather than JSON because the attempt pipeline reads
 * them with any provider absent.
 */
final class ItemSessionControl
{
    public const UNLIMITED = 0;

    public function __construct(
        public readonly int $maxAttempts = 1,
        public readonly bool $showFeedback = false,
        public readonly bool $allowReview = true,
        public readonly bool $showSolution = false,
        public readonly bool $allowComment = false,
        public readonly bool $allowSkipping = true,
        public readonly bool $validateResponses = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'max_attempts' => $this->maxAttempts,
            'show_feedback' => $this->showFeedback,
            'allow_review' => $this->allowReview,
            'show_solution' => $this->showSolution,
            'allow_comment' => $this->allowComment,
            'allow_skipping' => $this->allowSkipping,
            'validate_responses' => $this->validateResponses,
        ];
    }

    /**
     * @param array<string, mixed>|null $stored
     */
    public static function fromArray(?array $stored): self
    {
        if ($stored === null) {
            return new self();
        }

        $defaults = new self();

        return new self(
            \is_int($stored['max_attempts'] ?? null) ? (int) $stored['max_attempts'] : $defaults->maxAttempts,
            self::boolOr($stored, 'show_feedback', $defaults->showFeedback),
            self::boolOr($stored, 'allow_review', $defaults->allowReview),
            self::boolOr($stored, 'show_solution', $defaults->showSolution),
            self::boolOr($stored, 'allow_comment', $defaults->allowComment),
            self::boolOr($stored, 'allow_skipping', $defaults->allowSkipping),
            self::boolOr($stored, 'validate_responses', $defaults->validateResponses),
        );
    }

    /**
     * @param array<string, mixed> $stored
     */
    private static function boolOr(array $stored, string $key, bool $default): bool
    {
        $value = $stored[$key] ?? null;

        return \is_bool($value) ? $value : $default;
    }
}
