<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\ChoiceSequence;

/**
 * One row of the frozen manifest: a question as it was actually served to
 * one learner in one attempt.
 *
 * This is the single most important addition in the assessment foundation
 * and it is free-build work. Without it, random selection, versioning and
 * reproducible regrading are all impossible, an instructor editing a
 * definition mid-attempt silently rewrites how in-flight attempts are
 * graded, and a question published mid-attempt is graded although the
 * learner never saw it.
 *
 * presented is stored rather than recomputed for a second reason: it is what
 * lets an attempt containing a question from a removed package still render,
 * which is the hardest case of the rule that the paid package is a
 * dependency of nothing. It freezes storage refs and never a signed URL,
 * because a signature expires and a frozen expired URL breaks review,
 * appeals, manual grading and the removal placeholder on exactly the
 * image-bearing types that need them.
 */
final class AttemptQuestion
{
    /** @var array<string, mixed> */
    public readonly array $presented;

    /** @var array<string, mixed>|null */
    public readonly ?array $variant;

    /** @var array<string, mixed> */
    public readonly array $policyParams;

    /**
     * @param array<string, mixed>      $presented
     * @param array<string, mixed>|null $variant
     * @param array<string, mixed>      $policyParams
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $attemptId,
        public readonly int $position,
        public readonly int $screen,
        public readonly int $questionId,
        public readonly ?int $questionVersionId,
        public readonly float $points,
        public readonly bool $scored,
        array $presented,
        public readonly ChoiceSequence $choiceSequence,
        public readonly string $definitionHash,
        public readonly AnsweredStatus $answeredStatus = AnsweredStatus::NotPresented,
        ?array $variant = null,
        /**
         * The marking rule the placement overrode the question's own with.
         *
         * Frozen here rather than resolved at grading time, for the reason
         * everything else on this row is frozen: the slot may be edited, and
         * an attempt already served must be marked under the rule it was
         * served under. Null means no override, which is every row a free
         * composition ever wrote.
         */
        public readonly ?string $policyKey = null,
        array $policyParams = [],
    ) {
        $this->presented = $presented;
        $this->variant = $variant;
        $this->policyParams = $policyParams;
    }

    public function withAnsweredStatus(AnsweredStatus $status): self
    {
        return new self(
            $this->id,
            $this->attemptId,
            $this->position,
            $this->screen,
            $this->questionId,
            $this->questionVersionId,
            $this->points,
            $this->scored,
            $this->presented,
            $this->choiceSequence,
            $this->definitionHash,
            $status,
            $this->variant,
            $this->policyKey,
            $this->policyParams,
        );
    }
}
