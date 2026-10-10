<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Policy;

/**
 * The machine-readable reasons the free policies write, so the counters
 * screen groups by a published vocabulary rather than by whatever string a
 * policy happened to use.
 *
 * suppressed_reason is a real column for exactly this: counting suppressions
 * without their reason cannot tell a site whether a rule is throttled by the
 * frequency cap, blocked by consent or silenced by a competing rule.
 */
final class SuppressionReason
{
    public const PREFERENCE = 'preference_never';
    public const POLICY_DISALLOWED = 'policy_disallowed';
    public const CONSENT_MISSING = 'consent_missing';
    public const CONSENT_WITHDRAWN = 'consent_withdrawn';
    public const SUPPRESSED_ADDRESS = 'address_suppressed';
    public const CHANNEL_UNCONFIRMED = 'channel_unconfirmed';
    public const FREQUENCY_CAP = 'frequency_cap';
    public const DUPLICATE_WINDOW = 'duplicate_window';
    public const CROSS_RULE = 'cross_rule';
    public const CONDITIONS_NO_LONGER_HOLD = 'conditions_no_longer_hold';
    public const AUDIENCE_MISSING = 'audience_missing';
    public const CHANNEL_MISSING = 'channel_missing';
    public const CHANNEL_DISABLED = 'channel_disabled';
    public const TEMPLATE_MISSING = 'template_missing';
    public const NO_RECIPIENT_TIMEZONE = 'no_recipient_timezone';
    /**
     * No lawful unsubscribe mechanism resolves on this site, so a
     * non-transactional message may not go out. It is a configuration fact
     * rather than a recipient fact, which is why it is named separately from
     * a missing channel: an administrator reading the counters screen has to
     * be able to tell "this site never configured its unsubscribe base URL"
     * from "this channel is gone".
     */
    public const UNSUBSCRIBE_UNAVAILABLE = 'unsubscribe_unavailable';
    /**
     * The reason for the message is gone: the recipient's enrolment or
     * entitlement no longer holds at the moment the row would have sent.
     */
    public const ENROLMENT_NO_LONGER_ACTIVE = 'enrolment_no_longer_active';

    private function __construct()
    {
    }
}
