<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Policy;

use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationQueueRepository;
use Shondalai\VidyaLMS\Core\Registry\Context\NotificationDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\ActsAtCreation;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\NotificationPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;
use Shondalai\VidyaLMS\Core\Registry\Settings\DeclaresSettings;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsDeclarationContext;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsFields;

/**
 * Cancels an already-queued message for the same trigger and subject.
 *
 * This one stays at creation, because cancelling a pending row is its entire
 * purpose. It reads the subject columns and never parses the event
 * reference, which is an opaque identity no policy may parse.
 *
 * It denies nothing: the newer message wins and the older pending row is
 * cancelled, keeping its dedupe digest so its occurrence is never
 * re-enqueued behind the operator's back.
 */
final class DuplicateWindowPolicy implements NotificationPolicyInterface, ActsAtCreation, DeclaresSettings
{
    public const KEY = 'duplicate_window';
    public const SETTING_ENABLED = 'enabled';

    public function __construct(private readonly SqlNotificationQueueRepository $queue)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.policy.duplicate_window';
    }

    /**
     * Switching the duplicate window off is a real site decision, because
     * a site running a deliberate pair inside the window needs both to go
     * out.
     */
    public function settingsGroup(SettingsDeclarationContext $context): array
    {
        return [
            'key' => self::KEY,
            'label' => 'vidyalms.policy.duplicate_window',
            'description' => null,
            'fields' => [
                SettingsFields::boolean(
                    self::SETTING_ENABLED,
                    'vidyalms.settings.policy.duplicate_window.enabled',
                    true,
                    'vidyalms.settings.policy.duplicate_window.enabled.help',
                ),
            ],
        ];
    }

    /**
     * Nothing at send.
     *
     * Cancelling from the send-time fold inverts the window: the drain claims
     * in run-at order, so the **older** row is the one doing the cancelling
     * and the newer one is the row it cancels. The learner then receives the
     * stale message, and because the cancelled row keeps its digest the
     * current occurrence can never be re-enqueued either.
     */
    public function decide(Notification $notification, NotificationDecisionContext $context): PolicyDecision
    {
        return PolicyDecision::allow();
    }

    public function onEnqueued(Notification $notification, NotificationDecisionContext $context): void
    {
        if (!$context->boolSetting(self::SETTING_ENABLED, true) || $context->queueId === null) {
            return;
        }

        $this->queue->cancelPendingDuplicates(
            $context->triggerKey,
            $context->subjectType,
            $context->subjectId,
            $notification->recipientUserId,
            $context->queueId,
        );
    }
}
