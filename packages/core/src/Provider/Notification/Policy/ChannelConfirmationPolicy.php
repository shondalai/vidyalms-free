<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Policy;

use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\NotificationDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\NotificationPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\SuppressionReason;

/**
 * A channel address the recipient has not confirmed.
 *
 * The absence of a row means the host vouches for the address, which is what
 * an email address a person signed in with is. A row that exists and is not
 * confirmed denies, which is what a self-declared second address is until the
 * confirmation lands.
 *
 * Without a store the policy would either deny every non-exempt message on
 * every site or deny nothing, and neither is testable, which is why the
 * address table exists rather than a flag somewhere.
 */
final class ChannelConfirmationPolicy implements NotificationPolicyInterface
{
    public const KEY = 'channel_confirmation';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.policy.channel_confirmation';
    }

    public function decide(Notification $notification, NotificationDecisionContext $context): PolicyDecision
    {
        if ($notification->class->bypassesPreference()) {
            return PolicyDecision::allow();
        }

        $decision = PolicyDecision::allow();

        foreach ($context->channelKeys as $channelKey) {
            $row = $context->database->selectOne(
                Query::from('notification_channel_addresses')
                    ->select(['confirmed_at'])
                    ->where('user_id', '=', $notification->recipientUserId)
                    ->where('channel_key', '=', $channelKey),
            );

            if ($row !== null && $row['confirmed_at'] === null) {
                $decision = $decision->merge(
                    PolicyDecision::deny([$channelKey], SuppressionReason::CHANNEL_UNCONFIRMED),
                );
            }
        }

        return $decision;
    }
}
