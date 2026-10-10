<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Aggregation;

/**
 * One closed attempt's totals, as an aggregation method sees them.
 *
 * A flat value object rather than the Attempt entity, because an aggregator
 * is arithmetic over results and has no business reading an attempt's
 * policy snapshot, its enrolment or its served set.
 */
final class AttemptScore
{
    /**
     * @param array<string, mixed> $aggregationParams
     */
    public function __construct(
        public readonly int $attemptId,
        public readonly int $attemptNumber,
        public readonly ?float $score,
        public readonly ?float $maxScore,
        public readonly ?float $percentage,
        public readonly ?bool $passed,
        public readonly ?\DateTimeImmutable $submittedAt = null,
        /**
         * The counting and aggregation rules frozen on this attempt.
         *
         * Reporting from the live quiz row let an author edit
         * attempt_aggregation on a quiz with graded attempts and move every
         * learner's reported percentage instantly, with no grade row, no
         * domain event and no audit trail: the same auditability the design
         * demands of the negative marking rule, for the same reason. The
         * snapshot is written at StartAttempt and never moves, so a grade
         * reported from it is a grade the learner could have been told about
         * before they started.
         */
        public readonly ?string $attemptsCounted = null,
        public readonly ?string $aggregation = null,
        public readonly array $aggregationParams = [],
    ) {
    }

    /**
     * Whether this attempt has a result to aggregate at all. An attempt that
     * is still waiting on a human grade has none, and averaging a null as a
     * zero would report a learner as failing an essay nobody has marked yet.
     */
    public function isGraded(): bool
    {
        return $this->percentage !== null;
    }
}
