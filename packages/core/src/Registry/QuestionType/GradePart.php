<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\SessionStatus;

/**
 * One part of a multi-part result, so per-part reporting is groupable and a
 * try penalty can apply per part rather than per question. Applying the
 * penalty to the whole item is the bug Moodle shipped and fixed in
 * MDL-36955.
 *
 * A type with no parts returns none, and the roll-up row carries a null
 * part id.
 *
 * @phpstan-type PartShape array{
 *     part_id: string,
 *     fraction: float,
 *     raw_score: float|null,
 *     max_score: float|null,
 *     answered_status: string,
 *     score_status: string,
 *     session_status: string,
 *     feedback_ids: list<string>
 * }
 */
final class GradePart
{
    /** @var list<string> */
    public readonly array $feedbackIds;

    /**
     * @param list<string> $feedbackIds
     */
    public function __construct(
        public readonly string $partId,
        public readonly float $fraction,
        public readonly ?float $rawScore = null,
        public readonly ?float $maxScore = null,
        public readonly AnsweredStatus $answeredStatus = AnsweredStatus::Answered,
        public readonly ScoreStatus $scoreStatus = ScoreStatus::Scored,
        public readonly SessionStatus $sessionStatus = SessionStatus::Final,
        array $feedbackIds = [],
        /**
         * This part's own floor, from its own marking rule.
         *
         * Null falls back to the container's, which is what every part did
         * before this existed: a part whose rule floors at 0 was penalised
         * down to minus 1.5 because the roll-up carried a certainty part's
         * floor of minus 2. A part is marked under its own rule and is
         * floored under it too.
         */
        public readonly ?float $floorFraction = null,
    ) {
        $this->feedbackIds = array_values($feedbackIds);
    }

    /**
     * The floor this part is held at, its own when it has one.
     */
    public function floorAgainst(float $containerFloor): float
    {
        return $this->floorFraction ?? $containerFloor;
    }

    /**
     * @return PartShape
     */
    public function toArray(): array
    {
        return [
            'part_id' => $this->partId,
            'fraction' => $this->fraction,
            'raw_score' => $this->rawScore,
            'max_score' => $this->maxScore,
            'answered_status' => $this->answeredStatus->value,
            'score_status' => $this->scoreStatus->value,
            'session_status' => $this->sessionStatus->value,
            'feedback_ids' => $this->feedbackIds,
        ];
    }
}
