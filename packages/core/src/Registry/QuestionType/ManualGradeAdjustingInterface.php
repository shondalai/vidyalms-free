<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

/**
 * A multiplier the type applies to a human's mark, for the one kind of rule
 * that is neither correctness nor validity.
 *
 * The design names exactly one: an extended response whose `word_limit_mode`
 * is `penalise` multiplies the human's grade by
 * `max(0, 1 - word_limit_penalty * overage_ratio)`, proportional to the
 * overage rather than a step at the boundary, because a flat multiplier made
 * 801 words cost the same four marks out of twenty as 1500 words. Everything
 * else about a word limit is a validity constraint and never a deduction.
 *
 * It has to be a seam rather than a branch in GradeAnswerManually, because
 * the core has no idea what a word limit is: the rule, the counting and the
 * formula all live in the package that ships the type. It is optional and
 * additive, so a type that does not implement it is graded exactly as it was.
 *
 * The grade row keeps both numbers. `raw_score` is what the marker typed and
 * `points_awarded` is what the learner gets, so a percentage that dropped by
 * a deduction can be explained from the row rather than recomputed from the
 * definition, and a re-grade after the author changes the limit is a new row
 * rather than a silent restatement of the old one.
 */
interface ManualGradeAdjustingInterface
{
    /**
     * What a human's mark on this answer is multiplied by. 1.0 for no
     * deduction, which is what every mode but `penalise` returns.
     *
     * @param array<string, mixed> $definition
     * @param mixed                $answer     the learner's stored response
     * @param string|null          $partId     the part being marked, for a composite
     */
    public function manualGradeMultiplier(array $definition, mixed $answer, ?string $partId = null): float;
}
