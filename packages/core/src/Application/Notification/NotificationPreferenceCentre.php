<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationConsentRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationPreferenceRepository as Prefs;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationChannelRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsDigest;
use Shondalai\VidyaLMS\Core\Registry\Notification\SupportsHtmlBody;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRegistry;

/**
 * The caller's own preference centre, and nobody else's.
 *
 * It takes no user id and never discloses another person's preferences.
 *
 * A forced cell is shown and locked. It is offered only for transactional and
 * operational categories: an objection to direct marketing admits no
 * balancing, so a forced marketing category is refused at save rather than
 * discouraged in the form.
 *
 * Setting a category to never writes a consent row alongside the preference
 * row, with the basis recorded, exactly as the unsubscribe endpoint does. The
 * two paths must not disagree about the evidence.
 */
final class NotificationPreferenceCentre
{
    public function __construct(
        private readonly Prefs $preferences,
        private readonly SqlNotificationConsentRepository $consents,
        private readonly NotificationTriggerRegistry $triggers,
        private readonly NotificationChannelRegistry $channels,
        private readonly AccessGuard $guard,
        private readonly TranslatorPort $translator,
        /**
         * Where the digest folds are looked for. Optional so a harness that
         * predates the probe still builds this class; absent, no frequency is
         * a digest frequency, which is the safe direction and the one a build
         * with no fold registered actually delivers.
         */
        private readonly ?ScheduledTaskRegistry $digestHandlers = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function get(Principal $principal): array
    {
        $this->guard->requireAuthenticated($principal);

        return $this->present($principal->userId);
    }

    /**
     * @param list<mixed> $rows a decoded request body, so each entry is checked rather than trusted
     *
     * @return array<string, mixed>
     */
    public function put(Principal $principal, array $rows, ?string $ip = null): array
    {
        $this->guard->requireAuthenticated($principal);

        $categories = $this->triggers->categories();
        $channelKeys = $this->channels->keys();
        $errors = [];
        $accepted = [];

        foreach ($rows as $index => $row) {
            $categoryKey = \is_array($row) ? ($row['category_key'] ?? null) : null;
            $channelKey = \is_array($row) ? ($row['channel_key'] ?? null) : null;
            $frequency = \is_array($row) ? ($row['frequency'] ?? null) : null;

            if (!\is_string($categoryKey) || !isset($categories[$categoryKey])) {
                $errors['rows.' . $index . '.category_key'] = 'Unknown category.';
                continue;
            }

            if (!\is_string($channelKey) || !\in_array($channelKey, $channelKeys, true)) {
                $errors['rows.' . $index . '.channel_key'] = 'Unknown channel.';
                continue;
            }

            if (!\is_string($frequency) || !\in_array($frequency, Prefs::frequencies(), true)) {
                $errors['rows.' . $index . '.frequency'] = 'Unknown frequency.';
                continue;
            }

            if ($this->isDigest($frequency) && !$this->foldsDigest($channelKey, $frequency)) {
                $errors['rows.' . $index . '.frequency'] = 'This channel has no digest.';
                continue;
            }

            $state = $this->preferences->policies()[$categoryKey . '|' . $channelKey]['state'] ?? Prefs::POLICY_PERMITTED;

            if ($state === Prefs::POLICY_FORCED) {
                $errors['rows.' . $index . '.frequency'] = 'This category is locked by the site.';
                continue;
            }

            $accepted[] = [$categoryKey, $channelKey, $frequency];
        }

        // Nothing is written when anything is refused, so a client never has
        // to reason about a half-applied replacement.
        if ($errors !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ValidationException($errors);
        }

        foreach ($accepted as [$categoryKey, $channelKey, $frequency]) {
            $this->preferences->setPreference($principal->userId, $categoryKey, $channelKey, $frequency);

            $class = NotificationClass::from($categories[$categoryKey]['class']);

            if (!$class->requiresConsentCheck()) {
                // Transactional and operational messages do not run the
                // consent policy, so writing evidence for them would be a
                // ledger of decisions nothing reads.
                continue;
            }

            // Withdrawal must be as easy as giving, and giving has to be
            // possible at all: nothing in the product ever wrote a granted
            // row, so a category set to never and back stayed withdrawn and
            // the consent policy denied it permanently, with no path back. A
            // marketing category writes "objected" instead, because that is
            // what makes the permanence rule real and what an Article 21
            // objection actually is; an ordinary opt-out is a withdrawal.
            $state = match (true) {
                $frequency !== Prefs::FREQUENCY_NEVER => SqlNotificationConsentRepository::STATE_GRANTED,
                $class === NotificationClass::Marketing => SqlNotificationConsentRepository::STATE_OBJECTED,
                default => SqlNotificationConsentRepository::STATE_WITHDRAWN,
            };

            $this->consents->append(
                $principal->userId,
                $categoryKey,
                $state,
                SqlNotificationConsentRepository::BASIS_CONSENT,
                SqlNotificationConsentRepository::SOURCE_PREFERENCE_CENTRE,
                ['channel_key' => $channelKey, 'frequency' => $frequency],
                $principal->userId,
                $ip,
            );
        }

        return $this->present($principal->userId);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(int $userId): array
    {
        $categories = $this->triggers->categories();
        $policies = $this->preferences->policies();
        $chosen = $this->preferences->preferencesOf($userId);
        $rows = [];

        foreach ($categories as $categoryKey => $category) {
            $class = NotificationClass::from($category['class']);

            foreach ($this->channels->keys() as $channelKey) {
                $cell = $policies[$categoryKey . '|' . $channelKey] ?? null;
                $state = $cell['state'] ?? Prefs::POLICY_PERMITTED;

                if ($state === Prefs::POLICY_DISALLOWED) {
                    continue;
                }

                $available = $this->frequenciesFor($channelKey);
                $stored = $chosen[$categoryKey . '|' . $channelKey]
                    ?? ($cell['default_frequency'] ?? Prefs::FREQUENCY_IMMEDIATE);
                // A row written while a fold was installed names a frequency a
                // build without that fold no longer offers. Returning it
                // unchanged puts a control on the screen whose value is none
                // of its own options, and the client resubmits every editable
                // row, so one stale cell refuses the whole replacement. The
                // stored value is reported beside the substitution rather than
                // dropped, because the learner asked for it and a fold that
                // comes back should be able to honour it.
                $offered = \in_array($stored, $available, true) ? $stored : Prefs::FREQUENCY_IMMEDIATE;

                $rows[] = [
                    'category_key' => $categoryKey,
                    'channel_key' => $channelKey,
                    'frequency' => $offered,
                    'stored_frequency' => $offered === $stored ? null : $stored,
                    'policy_state' => $state,
                    'editable' => $state !== Prefs::POLICY_FORCED,
                    'bypasses_preference' => $class->bypassesPreference(),
                    'available_frequencies' => $available,
                ];
            }
        }

        return [
            'rows' => $rows,
            'channels' => $this->channelRows(),
            'categories' => $this->categoryRows($categories),
        ];
    }

    /**
     * What this channel may actually be set to, in the published order.
     *
     * A digest frequency is offered only where something folds it. Offering
     * one that nothing folds is a promise the build cannot keep: the learner
     * receives nothing at all and the pending items accumulate for ever with
     * no message to collapse into.
     *
     * @return list<string>
     */
    private function frequenciesFor(string $channelKey): array
    {
        return array_values(array_filter(
            Prefs::frequencies(),
            fn (string $frequency): bool => !$this->isDigest($frequency)
                || $this->foldsDigest($channelKey, $frequency),
        ));
    }

    /**
     * Whether any registered provider folds this channel at this frequency.
     *
     * The fold is a scheduled job, so the registry of jobs is where the
     * answer lives; the capability is what a provider declares rather than
     * a list of channel keys this class would have to be edited to extend.
     */
    private function foldsDigest(string $channelKey, string $frequency): bool
    {
        foreach ($this->digestHandlers?->all() ?? [] as $handler) {
            if ($handler instanceof SupportsDigest && $handler->foldsDigest($channelKey, $frequency)) {
                return true;
            }
        }

        return false;
    }

    private function isDigest(string $frequency): bool
    {
        return $frequency === Prefs::FREQUENCY_DAILY || $frequency === Prefs::FREQUENCY_WEEKLY;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function channelRows(): array
    {
        $rows = [];

        foreach ($this->channels->all() as $channel) {
            $rows[] = [
                'key' => $channel->key(),
                'label' => $this->translator->translate($channel->label()),
                'edition' => 'free',
                'available' => true,
                'capabilities' => $channel instanceof SupportsHtmlBody ? ['html_body'] : [],
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, array{class: string, declared_by: list<string>}> $categories
     *
     * @return list<array<string, mixed>>
     */
    private function categoryRows(array $categories): array
    {
        $rows = [];

        foreach ($categories as $key => $category) {
            $rows[] = [
                'key' => $key,
                'label' => $this->translator->translate('vidyalms.notification.category.' . $key),
                'class' => $category['class'],
                'declared_by' => $category['declared_by'],
            ];
        }

        return $rows;
    }
}
