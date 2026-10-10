<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Policy;

use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationQueueRepository;
use Shondalai\VidyaLMS\Core\Registry\Context\NotificationDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\NotificationPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;
use Shondalai\VidyaLMS\Core\Registry\Settings\DeclaresSettings;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsDeclarationContext;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsFields;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\SuppressionReason;

/**
 * A trailing twenty-four hour count per recipient per channel.
 *
 * It is evaluated at send against the twenty-four hours ending at the actual
 * send instant, and not at creation. A well-known platform counts at creation
 * because it creates at send; this design separates the two by days, so a
 * creation-time count is taken against a day that is not the day the message
 * lands. Measured at creation, a learner whose Saturday was busy loses a
 * message due the following Saturday, and a hundred rows created on a quiet
 * Tuesday for delivery on Monday all pass a cap they jointly breach on
 * arrival.
 *
 * Transactional messages are exempt: a cap that silences a certificate is a
 * cap in the wrong place.
 */
final class FrequencyCapPolicy implements NotificationPolicyInterface, DeclaresSettings
{
    public const KEY = 'frequency_cap';
    public const SETTING_MAX_PER_DAY = 'max_per_day';
    public const DEFAULT_MAX_PER_DAY = 20;

    public function __construct(private readonly SqlNotificationQueueRepository $queue)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.policy.frequency_cap';
    }

    /**
     * The daily ceiling. A cap nobody can raise suppresses a legitimate
     * cohort launch, and a cap nobody can lower is a complaint.
     */
    public function settingsGroup(SettingsDeclarationContext $context): array
    {
        return [
            'key' => self::KEY,
            'label' => 'vidyalms.policy.frequency_cap',
            'description' => null,
            'fields' => [
                SettingsFields::integer(
                    self::SETTING_MAX_PER_DAY,
                    'vidyalms.settings.policy.frequency_cap.max_per_day',
                    self::DEFAULT_MAX_PER_DAY,
                    'vidyalms.settings.policy.frequency_cap.max_per_day.help',
                    1,
                ),
            ],
        ];
    }

    public function decide(Notification $notification, NotificationDecisionContext $context): PolicyDecision
    {
        if ($notification->class->bypassesPreference()) {
            return PolicyDecision::allow();
        }

        $cap = $context->intSetting(self::SETTING_MAX_PER_DAY, self::DEFAULT_MAX_PER_DAY);

        if ($cap < 1) {
            return PolicyDecision::allow();
        }

        $since = $context->now->modify('-24 hours');
        $decision = PolicyDecision::allow();

        foreach ($context->channelKeys as $channelKey) {
            $sent = $this->queue->countSentSince($notification->recipientUserId, $channelKey, $since);

            if ($sent >= $cap) {
                $decision = $decision->merge(
                    PolicyDecision::deny([$channelKey], SuppressionReason::FREQUENCY_CAP),
                );
            }
        }

        return $decision;
    }
}
