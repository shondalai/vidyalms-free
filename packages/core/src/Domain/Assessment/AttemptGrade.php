<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\GradeState;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\SessionStatus;

/**
 * One immutable grading fact for one question of an attempt. Automatic
 * grading writes rows with a null grader id; manual grading writes rows with
 * the grader's user id. Rows are never updated or deleted; the latest row
 * per question wins when totals are recomputed.
 *
 * Widened additively, and every added field answers a question the old row
 * could not. The raw score is the mapping sum before clamping, which a
 * grader and a regrade both need and which cannot be reconstructed from the
 * award. The policy key and its parameters are what make an automated score
 * documentable, per Standard 4.19: without them the append-only history
 * proves what was awarded but not why. The session status is what separates
 * a pending human grade from an automatic zero, which is the difference
 * between an attempt correctly waiting and an attempt wrongly reading as
 * fully graded.
 */
final class AttemptGrade
{
    /** @var array<string, mixed> */
    public readonly array $scoringParams;

    /** @var list<string> */
    public readonly array $feedbackIds;

    /**
     * @param array<string, mixed> $scoringParams
     * @param list<string>         $feedbackIds
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $attemptId,
        public readonly ?int $questionId,
        public readonly ?int $graderId,
        public readonly float $pointsAwarded,
        public readonly float $maxPoints,
        public readonly ?string $feedback,
        public readonly \DateTimeImmutable $gradedAt,
        public readonly ?string $partId = null,
        public readonly ?float $rawScore = null,
        public readonly ?float $minScore = null,
        public readonly ?string $policyKey = null,
        array $scoringParams = [],
        public readonly SessionStatus $sessionStatus = SessionStatus::Final,
        public readonly ScoreStatus $scoreStatus = ScoreStatus::Scored,
        public readonly GradeState $state = GradeState::Incorrect,
        public readonly AnsweredStatus $answeredStatus = AnsweredStatus::Answered,
        array $feedbackIds = [],
        public readonly int $version = 1,
        /**
         * The rubric version this mark was made against.
         *
         * A real column on the row rather than a lookup through the
         * question, because editing a rubric creates a new version and must
         * never move a mark a rater already made: the row has to say which
         * version the rater was reading.
         */
        public readonly ?int $rubricVersionId = null,
    ) {
        $this->scoringParams = $scoringParams;
        $this->feedbackIds = array_values($feedbackIds);
    }

    public function isManual(): bool
    {
        return $this->graderId !== null;
    }

    /**
     * Whether this row is still waiting for a human. Read from the session
     * status rather than inferred from a zero award, because an automatic
     * zero and a pending grade look identical in points and mean opposite
     * things.
     */
    public function awaitsHuman(): bool
    {
        return $this->sessionStatus->awaitsHuman();
    }

    /**
     * Whether this row contributes to the attempt totals. A row that was
     * never scored contributes to neither sum, so a registry miss and an
     * unscored survey item are both invisible to the pass percentage rather
     * than being free marks or lost ones.
     */
    public function counts(): bool
    {
        return $this->scoreStatus === ScoreStatus::Scored;
    }

    /**
     * The roll-up row of a question, as opposed to one of its parts.
     */
    public function isRollUp(): bool
    {
        return $this->partId === null;
    }
}
