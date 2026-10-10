<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Scoring;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\SessionStatus;

/**
 * What a scoring policy returns: the clamped fraction, the raw number behind
 * it, and the parameters the policy actually used.
 *
 * The raw number survives because the mapping sum before clamping has to
 * reach the grader and a regrade; recording only the fraction makes a
 * dispute unanswerable. The parameters survive for the same reason, and
 * because Standard 4.19 requires an automated scoring algorithm to be
 * documentable: without them the append-only history proves what was
 * awarded but not why.
 */
final class RawScore
{
    public readonly float $fraction;

    /** @var list<string> */
    public readonly array $feedbackIds;

    /** @var array<string, mixed> */
    public readonly array $params;

    /**
     * @param list<string>         $feedbackIds
     * @param array<string, mixed> $params
     */
    public function __construct(
        float $fraction,
        public readonly ?float $raw = null,
        public readonly ?float $min = null,
        public readonly ?float $max = null,
        public readonly float $floorFraction = 0.0,
        public readonly ScoreStatus $status = ScoreStatus::Scored,
        public readonly SessionStatus $sessionStatus = SessionStatus::Final,
        array $feedbackIds = [],
        array $params = [],
    ) {
        $this->fraction = max($floorFraction, min(1.0, $fraction));
        $this->feedbackIds = array_values($feedbackIds);
        $this->params = $params;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function zero(array $params = []): self
    {
        return new self(0.0, 0.0, params: $params);
    }

    /**
     * The null-object outcome: raw 0 that is explicitly not a mark. An
     * unresolved scoring policy scores this rather than marking the answer
     * wrong.
     *
     * @param array<string, mixed> $params
     */
    public static function notScored(array $params = []): self
    {
        return new self(0.0, 0.0, status: ScoreStatus::NotScored, params: $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function pendingHuman(array $params = []): self
    {
        return new self(
            0.0,
            null,
            status: ScoreStatus::NotScored,
            sessionStatus: SessionStatus::PendingExternalScoring,
            params: $params,
        );
    }
}
