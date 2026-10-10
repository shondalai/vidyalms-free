<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;

/**
 * The installed template identities, with conflicting ownership refused.
 *
 * Trigger keys are already unique in the provider registry; template keys
 * are a second namespace. Silently merging two vocabularies under one stored
 * row would let whichever package registered first decide what the other
 * package is allowed to render.
 */
final class NotificationTemplateBlueprintCatalogue
{
    public function __construct(private readonly NotificationTriggerRegistry $triggers)
    {
    }

    public function forTemplate(string $templateKey): TemplateBlueprintRegistration
    {
        $matches = array_values(array_filter(
            $this->all(),
            static fn (TemplateBlueprintRegistration $registration): bool =>
                $registration->trigger->defaultTemplateKey() === $templateKey,
        ));

        if ($matches === []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unknown notification template "%s".', $templateKey));
        }

        return $matches[0];
    }

    /** @return list<TemplateBlueprintRegistration> */
    public function all(): array
    {
        $registrations = [];
        $owners = [];

        foreach ($this->triggers->all() as $trigger) {
            $templateKey = $trigger->defaultTemplateKey();

            if (isset($owners[$templateKey])) {
                throw new \LogicException(sprintf(
                    'Notification template "%s" is claimed by both "%s" and "%s".',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $templateKey,
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $owners[$templateKey],
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $trigger->key(),
                ));
            }

            $owners[$templateKey] = $trigger->key();
            $blueprint = $trigger instanceof DeclaresNotificationTemplateBlueprint
                ? $trigger->notificationTemplateBlueprint()
                : self::genericBlueprint($trigger->messageClass());

            $registrations[] = new TemplateBlueprintRegistration(
                $trigger,
                $blueprint,
                $blueprint->generic || !$trigger instanceof DeclaresNotificationTemplateBlueprint,
            );
        }

        usort(
            $registrations,
            static fn (TemplateBlueprintRegistration $left, TemplateBlueprintRegistration $right): int =>
                strcmp($left->trigger->defaultTemplateKey(), $right->trigger->defaultTemplateKey()),
        );

        return $registrations;
    }

    private static function genericBlueprint(NotificationClass $class): NotificationTemplateBlueprint
    {
        $samples = $class === NotificationClass::Transactional
            ? []
            : ['unsubscribe.url' => 'https://example.invalid/notifications/unsubscribe/sample'];

        return new NotificationTemplateBlueprint(1, [
            'subject' => 'Learning update',
            'preheader' => 'There is an update from your learning site.',
            'heading' => 'Learning update',
            'paragraphs' => ['Sign in to your learning site to review the latest update.'],
            'action_label' => null,
            'action_url' => null,
            'footer' => 'This message was sent by your learning site.',
            'logo_url' => null,
            'logo_alt' => null,
            'accent_color' => '#3155A6',
            'short_body' => 'There is a new learning update.',
        ], $samples, [], true);
    }
}
