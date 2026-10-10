<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;

/**
 * The unsubscribe token mechanism, named because there is no signing port in
 * the core: the only signing that exists is the object store's own signature
 * and is unrelated.
 *
 * The token is an HMAC-SHA256 over the user id, the category key and the
 * issue instant, keyed on a secret in the settings table. The previous secret
 * is accepted for ninety days after a rotation, because a rotation otherwise
 * invalidates every token in every message already sitting in an inbox.
 *
 * No table stores issued tokens: validity is derived, so there is nothing to
 * grow without bound and nothing to reconcile. Validity is long because the
 * mechanism must work at least thirty days after the message and no statute
 * gives a shorter window.
 */
final class UnsubscribeTokens
{
    public const SETTING_SECRET = 'notifications.unsubscribe_secret';
    public const SETTING_PREVIOUS_SECRET = 'notifications.unsubscribe_secret_previous';
    public const SETTING_ROTATED_AT = 'notifications.unsubscribe_secret_rotated_at';

    /** How long a token stays valid. Comfortably beyond every statutory floor. */
    public const VALIDITY_DAYS = 400;

    /** How long the displaced secret keeps working after a rotation. */
    public const OVERLAP_DAYS = 90;

    public function __construct(
        private readonly Settings $settings,
        private readonly ClockPort $clock,
        private readonly RandomPort $random,
    ) {
    }

    public function issue(int $userId, string $categoryKey): string
    {
        $issuedAt = $this->clock->now()->getTimestamp();
        $payload = $userId . '|' . $categoryKey . '|' . $issuedAt;
        $signature = hash_hmac('sha256', $payload, $this->secret());

        return rtrim(strtr(base64_encode($payload . '|' . $signature), '+/', '-_'), '=');
    }

    /**
     * @return array{user_id: int, category_key: string, issued_at: int}|null
     */
    public function verify(string $token): ?array
    {
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);

        if ($decoded === false) {
            return null;
        }

        $parts = explode('|', $decoded);

        if (\count($parts) !== 4) {
            return null;
        }

        [$userId, $categoryKey, $issuedAt, $signature] = $parts;

        if (preg_match('/^\d+$/', $userId) !== 1 || preg_match('/^\d+$/', $issuedAt) !== 1) {
            return null;
        }

        $payload = $userId . '|' . $categoryKey . '|' . $issuedAt;
        $now = $this->clock->now()->getTimestamp();

        if ((int) $issuedAt > $now + 60) {
            return null;
        }

        if ($now - (int) $issuedAt > self::VALIDITY_DAYS * 86400) {
            return null;
        }

        foreach ($this->acceptableSecrets() as $secret) {
            if (hash_equals(hash_hmac('sha256', $payload, $secret), $signature)) {
                return [
                    'user_id' => (int) $userId,
                    'category_key' => $categoryKey,
                    'issued_at' => (int) $issuedAt,
                ];
            }
        }

        return null;
    }

    /**
     * Rotating keeps the displaced secret working for the overlap, so a
     * rotation does not silently break every unsubscribe link already sent.
     */
    public function rotate(): void
    {
        $current = $this->settings->getString(self::SETTING_SECRET, '');

        if ($current !== '') {
            $this->settings->set(self::SETTING_PREVIOUS_SECRET, $current);
        }

        $this->settings->set(self::SETTING_SECRET, $this->random->uuid() . $this->random->verificationCode(32));
        $this->settings->set(self::SETTING_ROTATED_AT, $this->clock->now()->getTimestamp());
    }

    private function secret(): string
    {
        $secret = $this->settings->getString(self::SETTING_SECRET, '');

        if ($secret !== '') {
            return $secret;
        }

        return $this->settings->initialiseString(self::SETTING_SECRET,
            $this->random->uuid() . $this->random->verificationCode(32));
    }

    /**
     * @return list<string>
     */
    private function acceptableSecrets(): array
    {
        $secrets = [$this->secret()];
        $previous = $this->settings->getString(self::SETTING_PREVIOUS_SECRET, '');

        if ($previous === '') {
            return $secrets;
        }

        $rotatedAt = $this->settings->getInt(self::SETTING_ROTATED_AT, 0);
        $elapsed = $this->clock->now()->getTimestamp() - $rotatedAt;

        if ($rotatedAt > 0 && $elapsed <= self::OVERLAP_DAYS * 86400) {
            $secrets[] = $previous;
        }

        return $secrets;
    }
}
