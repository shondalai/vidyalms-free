<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\GradeState;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\SessionStatus;

/**
 * The outcome of grading one answer. The fraction is of the question's
 * points; the attempt grader multiplies.
 *
 * Widened additively, and the widening is a prerequisite rather than a
 * nicety. The old class threw on any fraction outside 0 to 1, which makes
 * the QTI mapping sum, the NCSBN plus/minus raw and the certainty range from
 * minus six to plus three all unrepresentable, so a policy would have had to
 * pre-clamp and lose the raw number the grader and a regrade both need. It
 * now **clamps** into [floorFraction, 1.0] and keeps the raw score beside
 * the clamped fraction.
 *
 * floorFraction defaults to 0. Nothing in the free build lowers it; it is
 * the seam that stops a per-entry negative fraction reaching the gradebook
 * on a quiz whose instructions promised no penalty, and the quiz-level
 * negative marking gate is what may lower it.
 *
 * requiresManualGrading is a derived accessor, not a field. What the author
 * declared (scoring intent), what has happened (session status) and whether
 * the mark counts (score status) are three different facts that one boolean
 * fused; deriving the boolean from the session status keeps every existing
 * caller working while the three facts stay separable.
 */
final class GradeResult
{
    public readonly float $fraction;

    /** @var list<GradePart> */
    public readonly array $parts;

    /** @var list<string> */
    public readonly array $feedbackIds;

    /** @var array<string, mixed> */
    public readonly array $scoringParams;

    /**
     * @param list<GradePart>      $parts
     * @param list<string>         $feedbackIds
     * @param array<string, mixed> $scoringParams
     */
    public function __construct(
        float $fraction,
        public readonly ?string $feedback = null,
        public readonly ?float $rawScore = null,
        public readonly ?float $minScore = null,
        public readonly ?float $maxScore = null,
        public readonly AnsweredStatus $answeredStatus = AnsweredStatus::Answered,
        public readonly ScoreStatus $scoreStatus = ScoreStatus::Scored,
        public readonly SessionStatus $sessionStatus = SessionStatus::Final,
        public readonly float $floorFraction = 0.0,
        array $parts = [],
        array $feedbackIds = [],
        public readonly ?string $policyKey = null,
        array $scoringParams = [],
    ) {
        if ($floorFraction > 1.0) {
            throw new \InvalidArgumentException('A grade floor cannot exceed 1.');
        }

        $this->fraction = max($floorFraction, min(1.0, $fraction));
        $this->parts = array_values($parts);
        $this->feedbackIds = array_values($feedbackIds);
        $this->scoringParams = $scoringParams;
    }

    public static function correct(?string $feedback = null): self
    {
        return new self(1.0, $feedback);
    }

    public static function incorrect(?string $feedback = null): self
    {
        return new self(0.0, $feedback);
    }

    public static function partial(float $fraction, ?string $feedback = null): self
    {
        return new self($fraction, $feedback);
    }

    /**
     * An item a human must mark. The fraction is 0 and the score status is
     * notscored, because nothing has been scored yet: an automatic zero
     * standing in for a pending human grade is what let an attempt with an
     * ungraded essay read as fully graded.
     */
    public static function manual(?string $feedback = null): self
    {
        return new self(
            0.0,
            $feedback,
            scoreStatus: ScoreStatus::NotScored,
            sessionStatus: SessionStatus::PendingExternalScoring,
        );
    }

    /**
     * The registry-miss result: an unresolvable type, an unresolvable
     * scoring policy or a definition version newer than the provider
     * supports. Raw 0 with notscored and an explanatory row, never a wrong
     * answer.
     */
    public static function notScored(?string $feedback = null): self
    {
        return new self(0.0, $feedback, scoreStatus: ScoreStatus::NotScored);
    }

    /**
     * A question the learner never saw or never opened. Distinct from a
     * wrong answer in every report.
     */
    public static function unanswered(AnsweredStatus $status = AnsweredStatus::Presented, ?string $feedback = null): self
    {
        return new self(0.0, $feedback, answeredStatus: $status);
    }

    public function requiresManualGrading(): bool
    {
        return $this->sessionStatus->awaitsHuman();
    }

    public function state(): GradeState
    {
        return GradeState::derive($this->fraction, $this->sessionStatus, $this->scoreStatus);
    }

    /**
     * The points this fraction is worth against an item maximum. Unscored
     * items contribute nothing, which is the same rule the totals apply.
     */
    public function awardedAgainst(float $maxPoints): float
    {
        if ($this->scoreStatus === ScoreStatus::NotScored) {
            return 0.0;
        }

        return round($this->fraction * $maxPoints, 2);
    }

    /*
     * Every wither below copies the whole record through **named** arguments
     * rather than positionally. This class is documented as widened
     * additively and every parameter after the first is defaulted, so the
     * intended evolution path is appending a fourteenth parameter. A
     * positional copy still compiles after that and silently drops whatever
     * the new field held, which is a wrong mark with nothing failing loudly.
     */

    public function withFeedback(?string $feedback): self
    {
        // Not through copy(), because null is a legitimate feedback value
        // and "leave it alone" and "clear it" would otherwise be the same
        // argument.
        return new self(
            fraction: $this->fraction,
            feedback: $feedback,
            rawScore: $this->rawScore,
            minScore: $this->minScore,
            maxScore: $this->maxScore,
            answeredStatus: $this->answeredStatus,
            scoreStatus: $this->scoreStatus,
            sessionStatus: $this->sessionStatus,
            floorFraction: $this->floorFraction,
            parts: $this->parts,
            feedbackIds: $this->feedbackIds,
            policyKey: $this->policyKey,
            scoringParams: $this->scoringParams,
        );
    }

    public function withAnsweredStatus(AnsweredStatus $status): self
    {
        return $this->copy(answeredStatus: $status);
    }

    /**
     * The same result at a different fraction, which is what a quiz-level
     * rule produces: a try penalty subtracts in fraction space and negative
     * marking lowers the floor the constructor clamps against.
     *
     * The raw score is deliberately left alone. It is the mapping sum the
     * policy produced, and a regrade and a grader both have to be able to
     * see what the marking rule said before the quiz policy acted on it.
     */
    public function withFraction(float $fraction, ?float $floorFraction = null): self
    {
        return $this->copy(fraction: $fraction, floorFraction: $floorFraction);
    }

    public function withSessionStatus(SessionStatus $status): self
    {
        return $this->copy(sessionStatus: $status);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function withPolicy(string $policyKey, array $params): self
    {
        return $this->copy(policyKey: $policyKey, scoringParams: $params);
    }

    /**
     * The per-part results a container type attaches to its roll-up.
     *
     * It lives here rather than inside the one container type that needs it,
     * because a private copy in a provider is a thirteen-argument positional
     * rebuild of a class core widens, and the next vendor container reinvents
     * the same one.
     *
     * @param list<GradePart> $parts
     */
    public function withParts(array $parts): self
    {
        return $this->copy(parts: $parts);
    }

    /**
     * @param list<GradePart>|null      $parts
     * @param list<string>|null         $feedbackIds
     * @param array<string, mixed>|null $scoringParams
     */
    private function copy(
        ?float $fraction = null,
        ?float $rawScore = null,
        ?float $minScore = null,
        ?float $maxScore = null,
        ?AnsweredStatus $answeredStatus = null,
        ?ScoreStatus $scoreStatus = null,
        ?SessionStatus $sessionStatus = null,
        ?float $floorFraction = null,
        ?array $parts = null,
        ?array $feedbackIds = null,
        ?string $policyKey = null,
        ?array $scoringParams = null,
    ): self {
        return new self(
            fraction: $fraction ?? $this->fraction,
            feedback: $this->feedback,
            rawScore: $rawScore ?? $this->rawScore,
            minScore: $minScore ?? $this->minScore,
            maxScore: $maxScore ?? $this->maxScore,
            answeredStatus: $answeredStatus ?? $this->answeredStatus,
            scoreStatus: $scoreStatus ?? $this->scoreStatus,
            sessionStatus: $sessionStatus ?? $this->sessionStatus,
            floorFraction: $floorFraction ?? $this->floorFraction,
            parts: $parts ?? $this->parts,
            feedbackIds: $feedbackIds ?? $this->feedbackIds,
            policyKey: $policyKey ?? $this->policyKey,
            scoringParams: $scoringParams ?? $this->scoringParams,
        );
    }
}
