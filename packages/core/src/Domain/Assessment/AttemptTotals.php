<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

/**
 * Pure derivation of attempt totals from grade rows: the latest grade row
 * per question wins, every older row stays as history.
 */
final class AttemptTotals
{
    private function __construct(
        public readonly float $score,
        public readonly float $maxScore,
        public readonly float $percentage,
    ) {
    }

    public static function fromPoints(float $score, float $maxScore): self
    {
        $score = round($score, 2);
        $maxScore = round($maxScore, 2);
        $percentage = $maxScore > 0.0 ? round($score / $maxScore * 100, 2) : 0.0;

        return new self($score, $maxScore, $percentage);
    }

    /**
     * A row that was never scored contributes to neither sum.
     *
     * That single rule covers three cases that used to be indistinguishable
     * from a wrong answer: an unscored survey question, a question whose
     * provider is not installed, and a question still waiting for a human.
     * Counting any of them in the denominator moves a pass percentage for a
     * reason that has nothing to do with what the learner knew, and counting
     * a pending human grade as zero is what let an attempt with an ungraded
     * essay read as fully graded.
     *
     * @param list<AttemptGrade> $grades in insertion order, oldest first
     */
    public static function fromGradeHistory(array $grades): self
    {
        $score = 0.0;
        $maxScore = 0.0;

        foreach (self::latestPerQuestion($grades) as $grade) {
            if (!$grade->counts()) {
                continue;
            }

            $score += $grade->pointsAwarded;
            $maxScore += $grade->maxPoints;
        }

        return self::fromPoints($score, $maxScore);
    }

    /**
     * @param list<AttemptGrade> $grades in insertion order, oldest first
     *
     * @return array<int, AttemptGrade> keyed by question id
     */
    public static function latestPerQuestion(array $grades): array
    {
        $latest = [];

        foreach ($grades as $grade) {
            // A part row reports one part of a multi-part item and is
            // grouped by part id, never by question. Letting one overwrite
            // the roll-up would silently report a part's mark as the whole
            // question's.
            if ($grade->questionId === null || !$grade->isRollUp()) {
                continue;
            }

            $latest[$grade->questionId] = $grade;
        }

        return $latest;
    }

    public function passed(int $passMark): bool
    {
        return $passMark > 0 ? $this->percentage >= $passMark : true;
    }
}
