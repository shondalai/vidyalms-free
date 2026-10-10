<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

/**
 * What a rule does once a step has failed for the last time.
 *
 * Four words, and the axis is deliberately separate from the retry ladder.
 * Exposing both obliges the product to validate their interaction rather than
 * letting one silently cancel the other: the named n8n bug is that enabling
 * Retry On Fail while On Error is set to a continue value makes Max Tries and
 * Wait Between Tries dead settings, and the node continues on the first
 * failure. Any pairing where the disposition makes the ladder inert is
 * refused at save.
 *
 * Park is the shipped default for any action whose `reversible` is false, and
 * that is readable rather than asserted: a webhook has delivered the moment
 * it returns 2xx, an emitted event that has already triggered other rules
 * cannot be un-emitted, and a model call's spend cannot be undone.
 */
enum FailureDisposition: string
{
    /**
     * Stop here and keep the run resumable with enough state to review. Make's
     * Break directive under a clearer name.
     */
    case Park = 'park';

    /** Stop the run. Later steps do not execute. */
    case Halt = 'halt';

    /** Record the failure and run the next step anyway. */
    case Continue = 'continue';

    /** Hand the run to the steps declared as the error branch. */
    case ErrorBranch = 'error_branch';

    /**
     * Whether this disposition makes a retry ladder pointless, which is what
     * the save-time pairing check reads.
     *
     * Continue and the error branch both move on from the first failure, so a
     * ladder behind either is a dead setting. Park and halt both stop, which
     * is what a ladder is for: the attempts happen before the stop.
     */
    public function cancelsRetries(): bool
    {
        return $this === self::Continue || $this === self::ErrorBranch;
    }
}
