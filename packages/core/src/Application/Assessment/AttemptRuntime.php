<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;

/**
 * The parts of an attempt's frozen policy snapshot a client needs in order
 * to render the attempt correctly.
 *
 * It is read from the attempt and never from the quiz, so changing a quiz
 * setting cannot move a learner who is already inside one. The shape lives
 * here rather than in a presenter because three routes answer it (the start,
 * the attempt view and the grading response) and one shape in three places
 * would drift.
 */
final class AttemptRuntime
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function of(AttemptPolicySet $policy): array
    {
        return [
            'behaviour' => $policy->behaviour,
            'substituted_from' => $policy->substitutedFromBehaviour(),
            'navigation_mode' => $policy->navigationMode(),
            'submission_mode' => $policy->submissionMode(),
            'one_per_page' => ($policy->settings[AttemptPolicySet::KEY_ONE_PER_PAGE] ?? true) === true,
            'expiry_policy' => $policy->expiryPolicy(),
            'grace_seconds' => $policy->graceSeconds(),
            'auto_submit' => $policy->autoSubmits(),
            'item_session_control' => $policy->itemSessionControl()->toArray(),
            'certainty' => $policy->certaintyScale(),
            // True when the learner's frozen profile declares a need whose
            // tooling blurs the browser window, in which case the runner
            // reports no integrity signals at all.
            'suppress_integrity_automation' => $policy->suppressesIntegrityAutomation(),
        ];
    }
}
