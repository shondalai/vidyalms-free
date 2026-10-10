<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Aggregation;

/**
 * The one reportable grade a learner has for a quiz, and where it came from.
 *
 * The source attempt is null for a method that computes rather than picks
 * (an average of the best three is not any one attempt), which is exactly
 * why the reportable grade is a value of its own rather than a pointer at an
 * attempt row.
 */
final class AggregatedGrade
{
    public function __construct(
        public readonly ?float $percentage,
        public readonly ?float $score = null,
        public readonly ?float $maxScore = null,
        public readonly ?bool $passed = null,
        public readonly ?int $sourceAttemptId = null,
        /**
         * The aggregation key the quiz asked for, and the one that actually
         * ran.
         *
         * They differ when the package providing the stored key is not
         * installed, and the difference is not cosmetic: removing the
         * package that provides average_best_n turns every affected
         * learner's reported grade from an average of their best three into
         * their single highest attempt, on the gradebook, on the learner's
         * own page and on any certificate keyed off it. A substituted
         * attempt behaviour is written onto the attempt and shown by the
         * runner; a substituted aggregation is a derived read with no row of
         * its own, so it travels with the grade or it is invisible.
         */
        public readonly ?string $requestedMethod = null,
        public readonly ?string $resolvedMethod = null,
    ) {
    }

    public static function from(AttemptScore $attempt): self
    {
        return new self(
            $attempt->percentage,
            $attempt->score,
            $attempt->maxScore,
            $attempt->passed,
            $attempt->attemptId,
        );
    }

    public function withMethod(string $requested, string $resolved): self
    {
        return new self(
            $this->percentage,
            $this->score,
            $this->maxScore,
            $this->passed,
            $this->sourceAttemptId,
            $requested,
            $resolved,
        );
    }

    /**
     * True when the grade was computed under a different rule from the one
     * the quiz named.
     */
    public function methodSubstituted(): bool
    {
        return $this->requestedMethod !== null
            && $this->resolvedMethod !== null
            && $this->requestedMethod !== $this->resolvedMethod;
    }
}
