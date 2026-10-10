<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

/**
 * Every quiz-level policy setting of one quiz, in one value object.
 *
 * None of it is visible to a question type. The whole set is resolved once
 * at StartAttempt and frozen onto the attempt, so a type has no parameter,
 * method or field through which a quiz policy could reach it, and changing a
 * setting cannot silently regrade an attempt already in flight.
 *
 * It is a value object rather than twenty-four more constructor parameters
 * on Quiz because the settings travel together everywhere: the settings
 * form writes the whole set, the attempt freezes the whole set, and a
 * partial one has no meaning.
 */
final class QuizPolicy
{
    public const NAVIGATION_LINEAR = 'linear';
    public const NAVIGATION_NONLINEAR = 'nonlinear';

    public const SUBMISSION_INDIVIDUAL = 'individual';
    public const SUBMISSION_SIMULTANEOUS = 'simultaneous';

    public const EXPIRY_AUTO_SUBMIT = 'auto_submit';
    public const EXPIRY_GRACE_PERIOD = 'grace_period';
    public const EXPIRY_MUST_SUBMIT = 'must_submit';

    public const COUNTED_ALL = 'all';
    public const COUNTED_FIRST = 'first';
    public const COUNTED_LAST = 'last';

    /**
     * 60 seconds is the floor for clock skew, not merely the default: below
     * it a submit that left the browser in time would be refused.
     */
    public const MIN_GRACE_SECONDS = 60;

    /** @var array<string, mixed>|null */
    public readonly ?array $attemptAggregationParams;

    /**
     * @param array<string, mixed>|null $attemptAggregationParams
     */
    public function __construct(
        public readonly ?string $behaviour = null,
        public readonly string $navigationMode = self::NAVIGATION_NONLINEAR,
        public readonly string $submissionMode = self::SUBMISSION_INDIVIDUAL,
        public readonly bool $shuffleQuestions = false,
        public readonly bool $shuffleOptions = false,
        public readonly bool $onePerPage = true,
        public readonly ?\DateTimeImmutable $opensAt = null,
        public readonly ?\DateTimeImmutable $closesAt = null,
        public readonly string $expiryPolicy = self::EXPIRY_AUTO_SUBMIT,
        public readonly int $graceSeconds = self::MIN_GRACE_SECONDS,
        public readonly bool $autoSubmit = true,
        public readonly ?int $cooldownAfterFirstSeconds = null,
        public readonly ?int $cooldownAfterLaterSeconds = null,
        public readonly string $attemptsCounted = self::COUNTED_ALL,
        public readonly string $attemptAggregation = 'highest',
        ?array $attemptAggregationParams = null,
        public readonly ?string $negativeMarkingPolicy = null,
        public readonly bool $allowQuizNegative = false,
        public readonly float $tryPenalty = 0.0,
        public readonly ItemSessionControl $itemSessionControl = new ItemSessionControl(),
        public readonly ?ReviewVisibility $reviewVisibility = null,
        /**
         * The composition that decides what an attempt of this quiz is
         * served, when the quiz's form is not simply its own published
         * questions.
         *
         * Null is the free default. A quiz whose whole form is a placement
         * tree names the composition that reads it, so StartAttempt can tell
         * a quiz nobody has written questions for from a quiz whose form the
         * installed build cannot read: the first is a survey in progress,
         * the second used to serve a learner an empty exam that submitted,
         * recorded as graded, consumed an attempt and passed at a pass mark
         * of 0.
         */
        public readonly ?string $composition = null,
    ) {
        $this->attemptAggregationParams = $attemptAggregationParams;
    }

    /**
     * @return list<string>
     */
    public static function navigationModes(): array
    {
        return [self::NAVIGATION_LINEAR, self::NAVIGATION_NONLINEAR];
    }

    /**
     * @return list<string>
     */
    public static function submissionModes(): array
    {
        return [self::SUBMISSION_INDIVIDUAL, self::SUBMISSION_SIMULTANEOUS];
    }

    /**
     * @return list<string>
     */
    public static function expiryPolicies(): array
    {
        return [self::EXPIRY_AUTO_SUBMIT, self::EXPIRY_GRACE_PERIOD, self::EXPIRY_MUST_SUBMIT];
    }

    /**
     * @return list<string>
     */
    public static function attemptsCountedValues(): array
    {
        return [self::COUNTED_ALL, self::COUNTED_FIRST, self::COUNTED_LAST];
    }

    /**
     * The same policy, naming the composition that builds this quiz's form.
     *
     * A copy rather than a setter because the policy is immutable, and one
     * method rather than a rebuild at every call site because a positional
     * rebuild of a twenty-member value object is how a member gets dropped.
     */
    public function withComposition(?string $composition): self
    {
        return new self(
            $this->behaviour,
            $this->navigationMode,
            $this->submissionMode,
            $this->shuffleQuestions,
            $this->shuffleOptions,
            $this->onePerPage,
            $this->opensAt,
            $this->closesAt,
            $this->expiryPolicy,
            $this->graceSeconds,
            $this->autoSubmit,
            $this->cooldownAfterFirstSeconds,
            $this->cooldownAfterLaterSeconds,
            $this->attemptsCounted,
            $this->attemptAggregation,
            $this->attemptAggregationParams,
            $this->negativeMarkingPolicy,
            $this->allowQuizNegative,
            $this->tryPenalty,
            $this->itemSessionControl,
            $this->reviewVisibility,
            $composition,
        );
    }

    public function isLinear(): bool
    {
        return $this->navigationMode === self::NAVIGATION_LINEAR;
    }

    public function gradesAtSubmitOnly(): bool
    {
        return $this->submissionMode === self::SUBMISSION_SIMULTANEOUS;
    }

    /**
     * The review matrix in force, derived from the coarse switch when the
     * quiz stores none, so the two can never disagree.
     */
    public function reviewVisibilityFor(string $showResults): ReviewVisibility
    {
        return $this->reviewVisibility ?? ReviewVisibility::derivedFrom($showResults);
    }

    /**
     * The cooldown that applies after the learner's Nth attempt. Two
     * durations rather than one, because the common pedagogical pattern is a
     * short gap after the first failure and a longer one after that.
     */
    public function cooldownAfter(int $attemptNumber): ?int
    {
        $seconds = $attemptNumber <= 1
            ? $this->cooldownAfterFirstSeconds
            : ($this->cooldownAfterLaterSeconds ?? $this->cooldownAfterFirstSeconds);

        return $seconds !== null && $seconds > 0 ? $seconds : null;
    }
}
