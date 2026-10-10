<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation;

/**
 * Whether the options of an item are shuffled for this attempt, and why not
 * when they are not.
 *
 * Shuffle is the one quiz-level policy that reaches into an item, and it
 * reaches the interaction primitive rather than the question type. Core
 * resolves quizzes.shuffle_options and the learner's accessibility profile
 * into this object, hands it to the primitive, and writes the resulting
 * sequence to attempt_questions.choice_sequence. A third-party type
 * inherits seeded shuffle that respects the per-choice fixed flag without
 * writing a line of it.
 */
final class ShufflePolicy
{
    public const REASON_QUIZ_SETTING = 'quiz_setting';
    public const REASON_ACCESSIBILITY_PROFILE = 'accessibility_profile';

    private function __construct(
        public readonly bool $enabled,
        public readonly ?string $suppressedBecause,
    ) {
    }

    public static function on(): self
    {
        return new self(true, null);
    }

    public static function off(string $reason = self::REASON_QUIZ_SETTING): self
    {
        return new self(false, $reason);
    }

    /**
     * Suppressed when the learner profile asks for a simplified or
     * single-column layout, unless randomisation is intrinsic to the
     * construct, which is the type's declaration and not this object's.
     */
    public static function resolve(bool $quizWantsShuffle, bool $profileSuppresses): self
    {
        if ($profileSuppresses) {
            return self::off(self::REASON_ACCESSIBILITY_PROFILE);
        }

        return $quizWantsShuffle ? self::on() : self::off();
    }
}
