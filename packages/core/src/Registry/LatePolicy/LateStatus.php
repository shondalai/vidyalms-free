<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\LatePolicy;

/**
 * What a late policy decided about a submission, beside what it did to the
 * score.
 *
 * Four states, because the score alone cannot carry the decision. A hard
 * cut-off does not mean "scored zero": zero is a mark somebody earned and
 * blocked is a submission the policy refused to take, and a gradebook that
 * cannot tell them apart cannot explain either. Missing is the third, and it
 * is the one a sweep writes rather than a submission: nothing arrived at
 * all.
 *
 * The enum is closed and lives in the core rather than in a provider,
 * because the grading path branches on it and a provider-defined status
 * would be a value the grade row could not interpret.
 */
enum LateStatus: string
{
    case OnTime = 'on_time';
    case Late = 'late';
    case Missing = 'missing';
    case Blocked = 'blocked';

    /**
     * Whether the submission was taken at all. A blocked submission is not a
     * grade of zero and must not be recorded as one.
     */
    public function wasAccepted(): bool
    {
        return $this === self::OnTime || $this === self::Late;
    }
}
