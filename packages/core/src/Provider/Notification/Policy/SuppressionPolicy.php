<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Policy;

use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationSuppressionRepository;
use Shondalai\VidyaLMS\Core\Registry\Context\NotificationDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\NotificationPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\SuppressionReason;

/**
 * A suppressed address, checked in a policy before the channel is called.
 *
 * Checking inside the mailer adapter is too late: a well-known provider
 * maintains a global suppression list and documents that a send to a
 * suppressed address succeeds at the API, is treated as a hard bounce, and
 * still counts against the sender's bounce rate.
 *
 * It has no class exemption by design. Suppression is a deliverability fact
 * about an address and binds every class, which is exactly why a preference
 * opt-out may not land in the suppression table.
 */
final class SuppressionPolicy implements NotificationPolicyInterface
{
    public const KEY = 'suppression';

    public function __construct(private readonly SqlNotificationSuppressionRepository $suppressions)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.policy.suppression';
    }

    public function decide(Notification $notification, NotificationDecisionContext $context): PolicyDecision
    {
        $digest = $context->recipientAddressDigest;

        if ($digest === null) {
            return PolicyDecision::allow();
        }

        $decision = PolicyDecision::allow();

        foreach ($context->channelKeys as $channelKey) {
            if ($this->suppressions->isSuppressed($channelKey, $digest)) {
                $decision = $decision->merge(
                    PolicyDecision::deny([$channelKey], SuppressionReason::SUPPRESSED_ADDRESS),
                );
            }
        }

        return $decision;
    }
}
