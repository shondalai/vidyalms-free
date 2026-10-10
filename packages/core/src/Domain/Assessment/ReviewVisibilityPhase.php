<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

/**
 * What a learner may see of their own attempt in one phase.
 *
 * Named members rather than a bit mask, so revealing something new is a key
 * and not a numbering scheme nobody can read afterwards.
 */
final class ReviewVisibilityPhase
{
    public function __construct(
        public readonly bool $marks = false,
        public readonly bool $correctness = false,
        public readonly bool $specificFeedback = false,
        public readonly bool $generalFeedback = false,
        public readonly bool $rightAnswer = false,
        public readonly bool $overallFeedback = false,
    ) {
    }

    public static function everything(): self
    {
        return new self(true, true, true, true, true, true);
    }

    public static function nothing(): self
    {
        return new self();
    }

    /**
     * @return array<string, bool>
     */
    public function toArray(): array
    {
        return [
            'marks' => $this->marks,
            'correctness' => $this->correctness,
            'specific_feedback' => $this->specificFeedback,
            'general_feedback' => $this->generalFeedback,
            'right_answer' => $this->rightAnswer,
            'overall_feedback' => $this->overallFeedback,
        ];
    }

    /**
     * @param array<string, mixed>|null $stored
     */
    public static function fromArray(?array $stored, self $default): self
    {
        if ($stored === null) {
            return $default;
        }

        return new self(
            self::boolOr($stored, 'marks', $default->marks),
            self::boolOr($stored, 'correctness', $default->correctness),
            self::boolOr($stored, 'specific_feedback', $default->specificFeedback),
            self::boolOr($stored, 'general_feedback', $default->generalFeedback),
            self::boolOr($stored, 'right_answer', $default->rightAnswer),
            self::boolOr($stored, 'overall_feedback', $default->overallFeedback),
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
