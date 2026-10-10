<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\Seed;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;

/**
 * One run of a learner through a quiz. Attempts are append-only: totals on
 * this row are always recomputed from attempt_grades rows, and no operation
 * may rewrite or delete grade history.
 */
final class Attempt
{
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_GRADED = 'graded';

    /**
     * Past its deadline under the grace_period policy: it may still be
     * submitted, and no further answer is accepted.
     */
    public const STATUS_OVERDUE = 'overdue';

    /**
     * Past the grace under must_submit. A late attempt is not counted, which
     * is what must_submit means, so it reaches neither the aggregation set
     * nor a regrade.
     */
    public const STATUS_ABANDONED = 'abandoned';

    /** @var array<string, mixed> */
    public readonly array $policySnapshot;

    /**
     * @param array<string, mixed> $policySnapshot the frozen quiz policy
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $quizId,
        public readonly int $enrolmentId,
        public readonly int $userId,
        public readonly int $attemptNumber,
        public readonly string $status,
        public readonly \DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $submittedAt,
        public readonly ?float $score,
        public readonly ?float $maxScore,
        public readonly ?float $percentage,
        public readonly ?bool $passed,
        public readonly ?int $timeSpentSeconds,
        public readonly ?string $presentationSeed = null,
        public readonly ?string $behaviour = null,
        array $policySnapshot = [],
        public readonly ?\DateTimeImmutable $expiresAt = null,
    ) {
        $this->policySnapshot = $policySnapshot;
    }

    /**
     * @param array<string, mixed> $policySnapshot
     */
    public static function start(
        int $quizId,
        int $enrolmentId,
        int $userId,
        int $attemptNumber,
        \DateTimeImmutable $startedAt,
        ?string $presentationSeed = null,
        ?string $behaviour = null,
        array $policySnapshot = [],
        ?\DateTimeImmutable $expiresAt = null,
    ): self {
        return new self(
            null,
            $quizId,
            $enrolmentId,
            $userId,
            $attemptNumber,
            self::STATUS_IN_PROGRESS,
            $startedAt,
            null,
            null,
            null,
            null,
            null,
            null,
            $presentationSeed,
            $behaviour,
            $policySnapshot,
            $expiresAt,
        );
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->quizId,
            $this->enrolmentId,
            $this->userId,
            $this->attemptNumber,
            $this->status,
            $this->startedAt,
            $this->submittedAt,
            $this->score,
            $this->maxScore,
            $this->percentage,
            $this->passed,
            $this->timeSpentSeconds,
            $this->presentationSeed,
            $this->behaviour,
            $this->policySnapshot,
            $this->expiresAt,
        );
    }

    /**
     * The seed everything presentational derives from. An attempt started
     * before the column existed has none, and gets a stable one derived from
     * its own id, so an old attempt still re-renders in one fixed order
     * rather than a different one on every read.
     */
    public function seed(): Seed
    {
        return $this->presentationSeed === null || $this->presentationSeed === ''
            ? Seed::forAttempt($this->id ?? 0)
            : Seed::of($this->presentationSeed);
    }

    /**
     * The policy this attempt started under, never the quiz's current one.
     */
    public function policy(): AttemptPolicySet
    {
        return AttemptPolicySet::fromArray(
            $this->policySnapshot === [] ? null : $this->policySnapshot,
        );
    }

    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    /**
     * Overdue counts as not yet submitted, because nothing has been graded:
     * the deadline passed, further answers are refused, and the submit is
     * still to come from the learner, the browser or the sweeper.
     */
    public function isSubmitted(): bool
    {
        return $this->status !== self::STATUS_IN_PROGRESS && $this->status !== self::STATUS_OVERDUE;
    }

    public function isOwnedBy(int $userId): bool
    {
        return $this->userId === $userId;
    }

    /**
     * Whether this attempt reaches the reportable quiz grade. An attempt
     * still open has no result, and an abandoned one is not counted by the
     * definition of must_submit.
     */
    public function countsTowardsGrade(): bool
    {
        return $this->status === self::STATUS_SUBMITTED || $this->status === self::STATUS_GRADED;
    }

    /**
     * Whether the learner may still answer. An overdue attempt is open in
     * the sense that it may be submitted, and closed in the sense that no
     * further answer is accepted, which is the whole of what grace_period
     * buys.
     */
    public function acceptsAnswers(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    /**
     * Whether a submit is still possible: the learner's own submit, the
     * browser's auto-submit, or the sweeper's.
     */
    public function acceptsSubmission(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS || $this->status === self::STATUS_OVERDUE;
    }
}
