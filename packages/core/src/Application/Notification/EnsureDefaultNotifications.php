<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationRule;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationRuleRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationTemplateRepository;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\SweepTriggerInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationChannelRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsListHeaders;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprintCatalogue;

/**
 * Seeds one rule and one template per registered trigger, once.
 *
 * The rules the shipped copy already promises are seeded enabled, because
 * promising mail the product does not send is a defect and two of those mails
 * are promised on screen in so many words. Everything the research says to be
 * careful with is seeded disabled: light-touch nudges are small, context
 * dependent and sometimes negative, so the sweep and the unlock notice arrive
 * switched off and a site turns them on deliberately.
 *
 * It is guarded by a signature of the trigger key set, so it writes when the
 * set actually moves and not on every boot, exactly as the provider ledger
 * is. A row an author has edited is never overwritten: seeding checks for
 * absence and nothing else.
 */
final class EnsureDefaultNotifications
{
    public const SIGNATURE_SETTING = 'vidyalms.notifications.seed_signature';

    /** Reconcile system drafts left unpublished by MySQL JSON object-key normalization. */
    private const SEED_ALGORITHM = 'studio-seed-2';

    /** The triggers seeded switched off, and the reason each one is. */
    private const SEEDED_DISABLED = [
        'vidyalms.mail.content_unlocked',
        // The scheduled seat summary. The two seat alerts beside it are
        // seeded enabled because they say something an administrator has to
        // act on; a periodic summary about a pool that is fine is exactly the
        // light-touch nudge the research says to be careful with, and a daily
        // mail per pool is how an operator learns to filter the two that
        // matter into the same folder.
        'vidyalms.mail.seats_summary',
    ];

    public function __construct(
        private readonly NotificationTriggerRegistry $triggers,
        private readonly SqlNotificationRuleRepository $rules,
        private readonly SqlNotificationTemplateRepository $templates,
        private readonly TranslatorPort $translator,
        private readonly LocalePort $locale,
        private readonly Settings $settings,
        private readonly ?NotificationTemplateBlueprintCatalogue $blueprints = null,
        private readonly ?NotificationTemplateCompiler $compiler = null,
        private readonly ?NotificationChannelRegistry $channels = null,
    ) {
    }

    public function execute(): void
    {
        $keys = $this->triggers->keys();
        sort($keys);
        if ($this->blueprints !== null && $this->channels !== null) {
            // Includes content as well as versions: a forgotten version bump must not
            // leave a newly installed blueprint/channel invisible forever.
            foreach ($this->blueprints->all() as $registration) {
                $keys[] = $registration->trigger->defaultTemplateKey() . ':' . hash('sha256', json_encode([
                    $registration->blueprint->version, $registration->blueprint->source,
                ], JSON_THROW_ON_ERROR));
            }
            foreach ($this->channels->all() as $channel) {
                $keys[] = $channel->key() . ':' . ($channel instanceof SupportsListHeaders ? 'list' : 'plain');
            }
            $keys[] = 'studio-layout-1:' . $this->locale->current();
            sort($keys);
        }
        $signature = hash('sha256', self::SEED_ALGORITHM . '|' . implode('|', $keys));

        if ($this->settings->getString(self::SIGNATURE_SETTING, '') === $signature) {
            return;
        }

        $locale = $this->locale->current();
        $reconciled = true;

        foreach ($this->triggers->all() as $trigger) {
            $templateKey = $trigger->defaultTemplateKey();
            if ($this->blueprints !== null && $this->compiler !== null && $this->channels !== null) {
                $reconciled = $this->seedBlueprint($templateKey, $locale) && $reconciled;
            } else {
                $subject = $this->translator->translate($templateKey . '.subject');
                $body = $this->translator->translate($templateKey . '.body');
                $this->templates->seed($templateKey, $locale, 'email', [
                    'subject' => $subject === $templateKey . '.subject' ? $templateKey : $subject,
                    'body_text' => $body === $templateKey . '.body' ? '' : $body,
                    'state' => SqlNotificationTemplateRepository::STATE_PUBLISHED,
                ]);
            }

            $this->rules->seedIfMissing(new NotificationRule(
                null,
                $trigger->key(),
                $trigger->key(),
                $trigger->defaultAudienceKey(),
                $trigger->categoryKey(),
                $templateKey,
                ['email'],
                state: $this->stateFor($trigger->key(), $trigger instanceof SweepTriggerInterface),
            ));
        }

        // A race is harmless to the business operation, but leaving the
        // signature dirty makes a later request re-check the interrupted
        // identity after the competing transaction is visible.
        $this->settings->set(self::SIGNATURE_SETTING, $reconciled ? $signature : '');
    }

    /** New identities go live; existing live customizations receive only an optional system draft. */
    private function seedBlueprint(string $key, string $siteLocale): bool
    {
        if ($this->blueprints === null || $this->compiler === null || $this->channels === null) {
            return true;
        }
        $reconciled = true;
        $registration = $this->blueprints->forTemplate($key);
        foreach ($this->channels->all() as $channel) {
            if (
                $registration->trigger->messageClass()->requiresUnsubscribeHeaders()
                && !$channel instanceof SupportsListHeaders
            ) {
                continue;
            }
            $parts = $this->compiler->compileForPublication($key, $registration->blueprint->source, $channel->key())->parts();
            // Shipped source is canonical English, not a pretend translation. A site's
            // existing localized customization remains live and receives no English overwrite.
            foreach (array_unique(['en-GB', $siteLocale]) as $locale) {
                $row = $this->templates->find($key, $locale, $channel->key());
                if ($row === null && $locale !== 'en-GB') {
                    continue;
                }
                try {
                    if ($row === null) {
                        $row = $this->templates->saveDraft(
                            $key,
                            $locale,
                            $channel->key(),
                            $registration->blueprint->source,
                            0,
                            null,
                            SqlNotificationTemplateRepository::DRAFT_ORIGIN_SYSTEM,
                        );
                    }
                    if (
                        $row['state'] !== SqlNotificationTemplateRepository::STATE_PUBLISHED
                        && ($row['draft_origin'] ?? null) === SqlNotificationTemplateRepository::DRAFT_ORIGIN_SYSTEM
                        && self::comparableSource($row['draft_source'] ?? null)
                            === self::comparableSource($registration->blueprint->source)
                    ) {
                        // Also repairs an interrupted first seed without overwriting an operator draft.
                        $this->templates->publishDraft(
                            $key,
                            $locale,
                            $channel->key(),
                            (int) $row['draft_revision'],
                            $parts,
                            null,
                            $registration->blueprint->version,
                        );
                    } elseif (
                        $row['state'] === SqlNotificationTemplateRepository::STATE_PUBLISHED
                        && ($row['published_source'] ?? null) === null
                        && ($row['draft_source'] ?? null) === null
                        && (int) ($row['draft_revision'] ?? 0) === 0
                        && $locale === 'en-GB'
                    ) {
                        $this->templates->saveDraft(
                            $key,
                            $locale,
                            $channel->key(),
                            $registration->blueprint->source,
                            0,
                            null,
                            SqlNotificationTemplateRepository::DRAFT_ORIGIN_SYSTEM,
                        );
                    }
                } catch (ConflictException) {
                    // Another request seeded or an operator edited this exact identity.
                    // Do not abort the enrolment/progress transaction that happened
                    // to discover defaults. A later invocation re-checks the winner.
                    $reconciled = false;
                }
            }
        }

        return $reconciled;
    }

    private function stateFor(string $triggerKey, bool $isSweep): string
    {
        if ($isSweep || \in_array($triggerKey, self::SEEDED_DISABLED, true)) {
            return NotificationRule::STATE_DISABLED;
        }

        return NotificationRule::STATE_ENABLED;
    }

    /** JSON objects have no key order; lists and scalar types retain their exact meaning. */
    private static function comparableSource(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value);
        }
        return array_map(self::comparableSource(...), $value);
    }
}
