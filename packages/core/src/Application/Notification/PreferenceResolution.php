<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationPreferenceRepository as Prefs;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\SuppressionReason;

/**
 * A pure function over the site policy, the learner's preference and the
 * message class, with no input and output beyond its arguments.
 *
 * It sits beside the completion engine as a second pure engine rather than
 * inside any channel, because the rule it encodes is the one most often got
 * wrong: user preference and site policy are different things with different
 * lifecycles and different legal weight, and conflating them is the common
 * bug.
 *
 * Forced locks the cell and overrides a preference; disallowed silences the
 * category whatever the learner chose; permitted seeds a new account and
 * leaves the choice to the learner. A transactional message bypasses the
 * whole question.
 */
final class PreferenceResolution
{
    private function __construct(
        public readonly bool $allowed,
        public readonly ?string $reason,
        public readonly string $frequency,
    ) {
    }

    public static function resolve(?string $policyState, ?string $preference, NotificationClass $class): self
    {
        if ($class->bypassesPreference()) {
            return new self(true, null, Prefs::FREQUENCY_IMMEDIATE);
        }

        $state = $policyState ?? Prefs::POLICY_PERMITTED;

        if ($state === Prefs::POLICY_DISALLOWED) {
            return new self(false, SuppressionReason::POLICY_DISALLOWED, Prefs::FREQUENCY_NEVER);
        }

        if ($state === Prefs::POLICY_FORCED) {
            return new self(true, null, Prefs::FREQUENCY_IMMEDIATE);
        }

        $frequency = $preference ?? Prefs::FREQUENCY_IMMEDIATE;

        if ($frequency === Prefs::FREQUENCY_NEVER) {
            return new self(false, SuppressionReason::PREFERENCE, Prefs::FREQUENCY_NEVER);
        }

        return new self(true, null, $frequency);
    }

    public function isDigest(): bool
    {
        return $this->frequency === Prefs::FREQUENCY_DAILY || $this->frequency === Prefs::FREQUENCY_WEEKLY;
    }
}
