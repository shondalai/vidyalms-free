<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationRule;
use Shondalai\VidyaLMS\Core\Port\Authorization\AuthorizationPort;
use Shondalai\VidyaLMS\Core\Port\Authorization\HoldersOfCapability;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\HonoursOrgScope;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationChannelRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\ReportsDeliveryFeedback;
use Shondalai\VidyaLMS\Core\Registry\Notification\StatutoryQuietHours;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsAttachments;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsBatchSend;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsHtmlBody;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsListHeaders;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsShortForm;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\SweepTriggerInterface;
use Shondalai\VidyaLMS\Core\Registry\ProviderEdition;
use Shondalai\VidyaLMS\Core\Registry\ProviderPackages;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorRegistry;

/**
 * What the notification rules screen renders itself from.
 *
 * Categories are declared by trigger providers rather than stored, so there
 * is no categories table and a row cannot name a category no provider owns.
 * A new template key inherits an existing category and needs no preference
 * migration, which is why category and not template is the preference unit.
 *
 * A channel's capabilities are published rather than inferred, because what a
 * channel can carry is probed with instanceof and never branched on by key: a
 * channel with no list-header support may not carry a non-transactional
 * message, and a screen that could not see that would offer the combination.
 */
final class NotificationCatalogue
{
    public function __construct(
        private readonly NotificationTriggerRegistry $triggers,
        private readonly AudienceResolverRegistry $audiences,
        private readonly NotificationChannelRegistry $channels,
        private readonly ScheduleAnchorRegistry $anchors,
        private readonly AccessGuard $guard,
        private readonly TranslatorPort $translator,
        private readonly AuthorizationPort $authorization,
        private readonly ?ProviderPackages $packages = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Principal $principal): array
    {
        $this->guard->requireAny($principal, Capability::MANAGE, Capability::ADMIN);

        return [
            'triggers' => $this->triggerRows(),
            'audiences' => $this->audienceRows(),
            'channels' => $this->channelRows(),
            'categories' => $this->categoryRows(),
            'schedule_kinds' => $this->scheduleKindRows(),
            'anchors' => $this->anchorRows(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function triggerRows(): array
    {
        $rows = [];

        foreach ($this->triggers->all() as $trigger) {
            $rows[] = [
                'key' => $trigger->key(),
                'label' => $this->translator->translate($trigger->label()),
                'description' => null,
                'kind' => $trigger->kind(),
                'edition' => ProviderEdition::of(
                    $this->packages,
                    NotificationTriggerRegistry::EXTENSION_POINT,
                    $trigger->key(),
                ),
                'category_key' => $trigger->categoryKey(),
                'class' => $trigger->messageClass()->value,
                'default_audience_key' => $trigger->defaultAudienceKey(),
                'default_template_key' => $trigger->defaultTemplateKey(),
                'available' => true,
                'supports_one_time_only' => $trigger instanceof SweepTriggerInterface,
                'placeholders' => $trigger->declaredPlaceholders(),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function audienceRows(): array
    {
        $canEnumerate = $this->authorization instanceof HoldersOfCapability;
        $rows = [];

        foreach ($this->audiences->all() as $audience) {
            // The site managers audience is unavailable rather than silently
            // empty when the host cannot enumerate capability holders, so a
            // screen says so instead of a rule counting suppressions nobody
            // can explain.
            $needsEnumeration = $audience->key() === 'site_managers';

            $rows[] = [
                'key' => $audience->key(),
                'label' => $this->translator->translate($audience->label()),
                'description' => null,
                'edition' => ProviderEdition::of(
                    $this->packages,
                    AudienceResolverRegistry::EXTENSION_POINT,
                    $audience->key(),
                ),
                'available' => !$needsEnumeration || $canEnumerate,
                'respects_visibility_scope' => $audience instanceof FiltersByVisibility,
                // An audience that resolves only under an organisation scope
                // says so, rather than being offered as an ordinary choice
                // that silently reaches nobody on every send. The free
                // fan-out carries no scope, so today this flag is the whole
                // warning a rule editor has.
                'requires_org_scope' => $audience instanceof HonoursOrgScope,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function channelRows(): array
    {
        $rows = [];

        foreach ($this->channels->all() as $channel) {
            $capabilities = [];

            if ($channel instanceof SupportsHtmlBody) {
                $capabilities[] = 'html_body';
            }

            if ($channel instanceof SupportsShortForm) {
                $capabilities[] = 'short_form';
            }

            if ($channel instanceof SupportsAttachments) {
                $capabilities[] = 'attachments';
            }

            if ($channel instanceof SupportsListHeaders) {
                $capabilities[] = 'list_headers';
            }

            if ($channel instanceof SupportsBatchSend) {
                $capabilities[] = 'batch_send';
            }

            if ($channel instanceof ReportsDeliveryFeedback) {
                $capabilities[] = 'delivery_feedback';
            }

            $rows[] = [
                'key' => $channel->key(),
                'label' => $this->translator->translate($channel->label()),
                'edition' => ProviderEdition::of($this->packages, 'notification_channel', $channel->key()),
                'available' => true,
                'capabilities' => $capabilities,
                'quiet_hours_statutory' => $channel instanceof StatutoryQuietHours,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function categoryRows(): array
    {
        $rows = [];

        foreach ($this->triggers->categories() as $key => $category) {
            $rows[] = [
                'key' => $key,
                'label' => $this->translator->translate('vidyalms.notification.category.' . $key),
                'class' => $category['class'],
                'declared_by' => $category['declared_by'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scheduleKindRows(): array
    {
        return [
            [
                'key' => NotificationRule::KIND_IMMEDIATE,
                'label' => 'vidyalms.notification.schedule.immediate',
                'params_schema' => ['type' => 'object', 'properties' => [], 'additionalProperties' => false],
            ],
            [
                'key' => NotificationRule::KIND_ABSOLUTE,
                'label' => 'vidyalms.notification.schedule.absolute',
                'params_schema' => [
                    'type' => 'object',
                    'required' => ['at'],
                    'properties' => ['at' => ['type' => 'string', 'format' => 'date-time']],
                ],
            ],
            [
                'key' => NotificationRule::KIND_RELATIVE,
                'label' => 'vidyalms.notification.schedule.relative',
                'params_schema' => [
                    'type' => 'object',
                    'required' => ['anchor_key', 'offset_seconds'],
                    'properties' => [
                        'anchor_key' => ['type' => 'string'],
                        // A signed integer in seconds with a unit hint, never
                        // a whole-day count: a whole-day delay field fires
                        // every message in a sequence at the same clock hour
                        // with no local-time control.
                        'offset_seconds' => ['type' => 'integer'],
                        'offset_unit' => ['type' => 'string', 'enum' => ['hours', 'days']],
                        'direction' => ['type' => 'string', 'enum' => ['before', 'after']],
                    ],
                ],
            ],
            [
                'key' => NotificationRule::KIND_RECURRING_WINDOW,
                'label' => 'vidyalms.notification.schedule.recurring_window',
                'params_schema' => [
                    'type' => 'object',
                    'required' => ['anchor_key', 'every', 'until'],
                    'properties' => [
                        'anchor_key' => ['type' => 'string'],
                        'every' => ['type' => 'integer', 'minimum' => 1],
                        'every_unit' => ['type' => 'string', 'enum' => ['hours', 'days']],
                        'until' => ['type' => 'integer', 'minimum' => 1],
                        'until_unit' => ['type' => 'string', 'enum' => ['hours', 'days']],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function anchorRows(): array
    {
        $rows = [];

        foreach ($this->anchors->all() as $anchor) {
            $rows[] = [
                'key' => $anchor->key(),
                'label' => $this->translator->translate($anchor->label()),
                'description' => null,
                'edition' => ProviderEdition::of(
                    $this->packages,
                    ScheduleAnchorRegistry::EXTENSION_POINT,
                    $anchor->key(),
                ),
                'available' => true,
            ];
        }

        return $rows;
    }
}
