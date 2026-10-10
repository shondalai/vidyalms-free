<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Progress;

/**
 * The pure heart of the spine: a deterministic decision over progress counts.
 * No ports, no queries, no clock; callers assemble a CompletionInput and get
 * a verdict. An unknown rule never completes a course.
 */
final class CompletionEngine
{
    public const RULE_ALL_LESSONS = 'all_lessons';
    public const RULE_PERCENTAGE = 'percentage';
    public const RULE_PASS_FINAL = 'pass_final';

    public function decide(CompletionInput $input): bool
    {
        return match ($input->rule) {
            self::RULE_ALL_LESSONS => $input->lessonsTotal > 0
                && $input->lessonsCompleted === $input->lessonsTotal,
            // Integer cross-multiplication: float division would deny exact
            // boundaries (29/50 at 58% computes to 57.999...).
            self::RULE_PERCENTAGE => $input->threshold !== null
                && $input->lessonsTotal > 0
                && $input->lessonsCompleted * 100 >= $input->threshold * $input->lessonsTotal,
            self::RULE_PASS_FINAL => $input->finalQuizPassed === true,
            default => false,
        };
    }
}
