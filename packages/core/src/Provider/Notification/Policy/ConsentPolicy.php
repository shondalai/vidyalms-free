<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Policy;

use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationConsentRepository;
use Shondalai\VidyaLMS\Core\Registry\Context\NotificationDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\NotificationPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\SuppressionReason;
use Shondalai\VidyaLMS\Core\Registry\Settings\DeclaresSettings;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsDeclarationContext;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsFields;

/**
 * Whether the recipient consented, for the classes that need consent.
 *
 * It runs only for optional and marketing messages, which is what the class
 * decides and what a caller may never override at runtime. A withdrawal or an
 * objection denies; an objection is permanent, so a later grant does not
 * revive it, which the consent repository enforces when it answers.
 *
 * The default when nothing was ever recorded is a site setting rather than a
 * literal: a site whose lawful basis is legitimate interests is not made to
 * write a consent row per learner before its own operational mail works.
 */
final class ConsentPolicy implements NotificationPolicyInterface, DeclaresSettings
{
    public const KEY = 'consent';

    /** The setting name for what an unrecorded consent means. */
    public const SETTING_DEFAULT_GRANTED = 'default_granted';

    public function __construct(private readonly SqlNotificationConsentRepository $consents)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.policy.consent';
    }

    /**
     * What an unrecorded consent means is a lawful-basis decision rather
     * than a default a package may pick for a site, so it needs a field an
     * administrator can see and change.
     */
    public function settingsGroup(SettingsDeclarationContext $context): array
    {
        return [
            'key' => self::KEY,
            'label' => 'vidyalms.policy.consent',
            'description' => null,
            'fields' => [
                SettingsFields::boolean(
                    self::SETTING_DEFAULT_GRANTED,
                    'vidyalms.settings.policy.consent.default_granted',
                    true,
                    'vidyalms.settings.policy.consent.default_granted.help',
                ),
            ],
        ];
    }

    public function decide(Notification $notification, NotificationDecisionContext $context): PolicyDecision
    {
        if (!$notification->class->requiresConsentCheck()) {
            return PolicyDecision::allow();
        }

        $state = $this->consents->currentState($notification->recipientUserId, $notification->categoryKey);

        if ($state === null) {
            return $context->boolSetting(self::SETTING_DEFAULT_GRANTED, true)
                ? PolicyDecision::allow()
                : PolicyDecision::deny($context->channelKeys, SuppressionReason::CONSENT_MISSING);
        }

        if ($state === SqlNotificationConsentRepository::STATE_GRANTED) {
            return PolicyDecision::allow();
        }

        return PolicyDecision::deny($context->channelKeys, SuppressionReason::CONSENT_WITHDRAWN);
    }
}
