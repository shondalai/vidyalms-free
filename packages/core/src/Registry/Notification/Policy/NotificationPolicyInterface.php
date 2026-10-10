<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Policy;

use Shondalai\VidyaLMS\Core\Registry\Context\NotificationDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * One veto or deferral over one message.
 *
 * Eight ship free: preference, consent, suppression, channel confirmation,
 * quiet hours, frequency cap, duplicate window and cross-rule suppression.
 * Adding "do not mail a learner who unenrolled in the last hour" is a ninth
 * class in a package, never a diff to a shipped one.
 *
 * Configuration lives in provider_settings and never in a column on the row
 * that uses it: a policy needing a dedicated column makes the ninth policy a
 * schema migration, which is the failure the registry exists to prevent, and
 * it privileges whichever providers shipped first.
 *
 * The miss direction is published: an unresolvable policy denies nothing.
 */
interface NotificationPolicyInterface extends ProviderInterface
{
    public function label(): string;

    public function decide(Notification $notification, NotificationDecisionContext $context): PolicyDecision;
}
