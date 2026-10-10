<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

/**
 * The provider folds pending digest items into one message.
 *
 * Declared by whatever actually performs the fold, which today is a scheduled
 * task handler, and probed with instanceof by the preference centre.
 *
 * **Default when absent: nothing folds.** A build with no declaring provider
 * offers immediate and never and no digest frequency at all, which is what a
 * free learner needed and did not get: the offer was a hardcoded channel list
 * while every collapse handler shipped in Pro, so choosing daily meant
 * receiving nothing for ever while the pending items accumulated with no
 * message to collapse into and nothing anywhere reporting it.
 *
 * The question is asked per channel and per frequency because both vary: a
 * fold that runs daily is not a weekly one, and a channel with no digest
 * template cannot carry either.
 */
interface SupportsDigest
{
    /**
     * Whether this provider folds items of the given channel at the given
     * frequency, which is one of SqlNotificationPreferenceRepository's
     * digest frequencies.
     */
    public function foldsDigest(string $channelKey, string $frequency): bool;
}
