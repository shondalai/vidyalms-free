<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Policy;

use Shondalai\VidyaLMS\Core\Application\Notification\PreferenceResolution;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationPreferenceRepository;
use Shondalai\VidyaLMS\Core\Registry\Context\NotificationDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\NotificationPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\SuppressionReason;

/**
 * The learner's own choice, and the site's matrix behind it.
 *
 * Transactional messages bypass preference entirely, exactly as a password
 * reset and a certificate must: they are not marketing and a site may be
 * obliged to send them.
 *
 * The digest frequencies are not a denial here. A daily or weekly preference
 * routes the message into a pending digest item, which the sender does before
 * the policy fold runs, so this policy only ever sees an immediate or a never.
 */
final class PreferencePolicy implements NotificationPolicyInterface
{
    public const KEY = 'preference';

    public function __construct(private readonly SqlNotificationPreferenceRepository $preferences)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.policy.preference';
    }

    public function decide(Notification $notification, NotificationDecisionContext $context): PolicyDecision
    {
        if ($notification->class->bypassesPreference()) {
            return PolicyDecision::allow();
        }

        $policies = $this->preferences->policies();
        $chosen = $this->preferences->preferencesOf($notification->recipientUserId);
        $decision = PolicyDecision::allow();

        foreach ($context->channelKeys as $channelKey) {
            $resolution = PreferenceResolution::resolve(
                $policies[$notification->categoryKey . '|' . $channelKey]['state'] ?? null,
                $chosen[$notification->categoryKey . '|' . $channelKey] ?? null,
                $notification->class,
            );

            if ($resolution->allowed) {
                continue;
            }

            $decision = $decision->merge(PolicyDecision::deny([$channelKey], $resolution->reason
                ?? SuppressionReason::PREFERENCE));
        }

        return $decision;
    }
}
