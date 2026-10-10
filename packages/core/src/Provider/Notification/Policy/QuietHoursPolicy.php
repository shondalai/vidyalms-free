<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Policy;

use Shondalai\VidyaLMS\Core\Registry\Context\NotificationDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\NotificationPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;
use Shondalai\VidyaLMS\Core\Registry\Settings\DeclaresSettings;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsDeclarationContext;
use Shondalai\VidyaLMS\Core\Registry\Settings\SettingsFields;

/**
 * Not now, at the next permitted local hour.
 *
 * This is the policy that makes the deny-or-defer split load-bearing rather
 * than decorative. Its required behaviour cannot be expressed by a deny set,
 * so registering it as a veto-only policy would turn every quiet-hours
 * message into a silent suppression, and a certificate mail landing at 23:40
 * inside a 22:00 to 07:00 window must be delivered late and never dropped.
 *
 * The instant only ever moves forward. Moving it backward would send a
 * message before the moment it was due, and combined with a recurrence that
 * steps a local calendar it would drift a whole schedule an hour a season.
 *
 * The recipient timezone is stamped on the queue row at enqueue and read from
 * the context here, defaulted to the site zone and never to the literal UTC:
 * a UTC 08:00 to 21:00 window is 03:00 to 16:00 in one common North American
 * zone, and a literal default would breach the statute a channel with a
 * statutory window may not defeat.
 */
final class QuietHoursPolicy implements NotificationPolicyInterface, DeclaresSettings
{
    public const KEY = 'quiet_hours';

    public const SETTING_START = 'start';
    public const SETTING_END = 'end';
    public const SETTING_ENABLED = 'enabled';

    /** Quiet by default from ten at night to seven in the morning, local. */
    public const DEFAULT_START = '22:00';
    public const DEFAULT_END = '07:00';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.policy.quiet_hours';
    }

    /**
     * The window and the switch. Both are local to the recipient rather
     * than to the site, which is why the two instants are wall-clock
     * strings.
     */
    public function settingsGroup(SettingsDeclarationContext $context): array
    {
        return [
            'key' => self::KEY,
            'label' => 'vidyalms.policy.quiet_hours',
            'description' => null,
            'fields' => [
                SettingsFields::boolean(
                    self::SETTING_ENABLED,
                    'vidyalms.settings.policy.quiet_hours.enabled',
                    false,
                    'vidyalms.settings.policy.quiet_hours.enabled.help',
                ),
                SettingsFields::string(
                    self::SETTING_START,
                    'vidyalms.settings.policy.quiet_hours.start',
                    self::DEFAULT_START,
                    'vidyalms.settings.policy.quiet_hours.start.help',
                ),
                SettingsFields::string(
                    self::SETTING_END,
                    'vidyalms.settings.policy.quiet_hours.end',
                    self::DEFAULT_END,
                    'vidyalms.settings.policy.quiet_hours.end.help',
                ),
            ],
        ];
    }

    public function decide(Notification $notification, NotificationDecisionContext $context): PolicyDecision
    {
        if (!$context->boolSetting(self::SETTING_ENABLED, false)) {
            return PolicyDecision::allow();
        }

        $start = $this->minutesOf($context->stringSetting(self::SETTING_START, self::DEFAULT_START));
        $end = $this->minutesOf($context->stringSetting(self::SETTING_END, self::DEFAULT_END));

        if ($start === null || $end === null || $start === $end) {
            return PolicyDecision::allow();
        }

        try {
            $zone = new \DateTimeZone($context->recipientTimezone);
        } catch (\Exception) {
            return PolicyDecision::allow();
        }

        $local = $context->now->setTimezone($zone);
        $minutes = ((int) $local->format('H')) * 60 + (int) $local->format('i');

        if (!$this->isQuiet($minutes, $start, $end)) {
            return PolicyDecision::allow();
        }

        $resume = $local->setTime(intdiv($end, 60), $end % 60, 0);

        if ($resume <= $local) {
            $resume = $resume->modify('+1 day');
        }

        return PolicyDecision::defer(
            $context->channelKeys,
            $resume->setTimezone(new \DateTimeZone('UTC')),
        );
    }

    /**
     * A window that crosses midnight is the normal case, so the comparison
     * is written for it rather than for the simpler one.
     */
    private function isQuiet(int $minutes, int $start, int $end): bool
    {
        if ($start < $end) {
            return $minutes >= $start && $minutes < $end;
        }

        return $minutes >= $start || $minutes < $end;
    }

    private function minutesOf(string $value): ?int
    {
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $parts) !== 1) {
            return null;
        }

        return ((int) $parts[1]) * 60 + (int) $parts[2];
    }
}
