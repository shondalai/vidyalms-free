<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Policy;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunIntent;
use Shondalai\VidyaLMS\Core\Registry\Context\AiDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;

/**
 * A unit-free cap on the calls one run may make.
 *
 * **This is the ceiling that binds a bridge which meters nothing.** A
 * bring-your-own-key route records unknown spend for ever and passes every
 * ceiling denominated in money or tokens, so a limit expressed as a count is
 * the only one it cannot walk through. That is why two of the free ceilings
 * are counts rather than sums, and why both are free rather than paid: a
 * ceiling that exists only in the paid build is not a ceiling.
 *
 * The count it compares against is the number of subjects the run intends to
 * fan out over, which is the honest upper bound available before anything has
 * run: a task makes at least one call per subject, and a recipe makes more.
 */
final class PerRunCallsPolicy implements AiPolicyInterface
{
    public const KEY = 'per_run_calls';

    public const SETTING = 'ceiling.per_run_calls';

    /** Enough for a lesson-by-lesson build over a large module, and not a course. */
    public const DEFAULT_CAP = 50;

    public const REASON_TOO_MANY_CALLS = 'ai.policy.per_run_calls_exceeded';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ai.policy.per_run_calls.label';
    }

    public function decide(AiRunIntent $intent, AiDecisionContext $context): PolicyDecision
    {
        $configured = $context->setting(self::SETTING, self::DEFAULT_CAP);
        $cap = \is_int($configured) ? $configured : (\is_numeric($configured) ? (int) $configured : self::DEFAULT_CAP);

        if ($cap <= 0) {
            // A site that typed zero has switched the feature off through the
            // ceiling rather than through the toggle, which is a strange way
            // to do it and an unambiguous one.
            return PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_TOO_MANY_CALLS);
        }

        return $intent->subjectCount > $cap
            ? PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_TOO_MANY_CALLS)
            : PolicyDecision::allow();
    }
}
