<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Policy;

use Shondalai\VidyaLMS\Core\Domain\Notification\QueueState;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationRuleRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\NotificationDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\NotificationPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\SuppressionReason;
use Shondalai\VidyaLMS\Core\Registry\Settings\DeclaresSettings;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsDeclarationContext;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsFields;

/**
 * One rule silencing another for the same subject inside a window.
 *
 * Sequences are rules that reference each other rather than a schedule shape
 * of their own, which is why a rule row carries the keys it suppresses.
 * Without cross-rule suppression a site that enables five sensible rules
 * mails a learner five times on completion day.
 */
final class CrossRulePolicy implements NotificationPolicyInterface, DeclaresSettings
{
    public const KEY = 'cross_rule';
    public const SETTING_WINDOW_HOURS = 'window_hours';
    public const DEFAULT_WINDOW_HOURS = 24;

    public function __construct(private readonly SqlNotificationRuleRepository $rules)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.policy.cross_rule';
    }

    /**
     * The window one rule silences another inside. A site that enables
     * five sensible rules mails a learner five times on completion day,
     * and this is how it stops without disabling a rule.
     */
    public function settingsGroup(SettingsDeclarationContext $context): array
    {
        return [
            'key' => self::KEY,
            'label' => 'vidyalms.policy.cross_rule',
            'description' => null,
            'fields' => [
                SettingsFields::integer(
                    self::SETTING_WINDOW_HOURS,
                    'vidyalms.settings.policy.cross_rule.window_hours',
                    self::DEFAULT_WINDOW_HOURS,
                    'vidyalms.settings.policy.cross_rule.window_hours.help',
                    1,
                ),
            ],
        ];
    }

    public function decide(Notification $notification, NotificationDecisionContext $context): PolicyDecision
    {
        if ($context->ruleId === null) {
            return PolicyDecision::allow();
        }

        $rule = $this->rules->find($context->ruleId);

        if ($rule === null || $rule->suppresses === []) {
            return PolicyDecision::allow();
        }

        $window = max(1, $context->intSetting(self::SETTING_WINDOW_HOURS, self::DEFAULT_WINDOW_HOURS));
        $since = $context->now->modify('-' . $window . ' hours')->format('Y-m-d H:i:s');

        foreach ($rule->suppresses as $ruleKey) {
            $other = $this->rules->findByKey($ruleKey);

            if ($other === null || $other->id === null) {
                continue;
            }

            $query = Query::from('notification_queue')
                ->select(['id'])
                ->where('rule_id', '=', $other->id)
                ->where('recipient_user_id', '=', $notification->recipientUserId)
                ->where('state', '=', QueueState::Sent->value)
                ->where('sent_at', '>=', $since);

            if ($context->subjectType !== null) {
                $query->where('subject_type', '=', $context->subjectType);
            }

            if ($context->subjectId !== null) {
                $query->where('subject_id', '=', $context->subjectId);
            }

            if ($context->database->selectOne($query) !== null) {
                return PolicyDecision::deny($context->channelKeys, SuppressionReason::CROSS_RULE);
            }
        }

        return PolicyDecision::allow();
    }
}
