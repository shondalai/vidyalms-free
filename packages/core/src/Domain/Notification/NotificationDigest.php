<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Notification;

/**
 * The dedupe digests, composed in one place so no caller spells one by hand.
 *
 * The queue digest is a SHA-256 over seven components, with a unique index on
 * the digest alone rather than on the columns: a direct unique index over
 * those columns exceeds the 767-byte key limit of legacy MySQL and MariaDB
 * row formats under utf8mb4, which the email log schema comment already
 * states.
 *
 * The intent row's digest carries no recipient and no channel, so a replayed
 * event writes one intent row and not two, and the per-recipient rows the
 * fan-out then writes carry the full seven components.
 *
 * The occurrence key is mandatory and is a real column, not only a digest
 * input. Without it the six remaining components are identical across every
 * occurrence of a repeating schedule, so a window bounded at eleven
 * occurrences enqueues the first and silently drops ten, and a terminal row
 * would occupy the unique space for that recipient and that message for the
 * life of the site.
 *
 * The rule component is a producer-qualified reference ("notification:42",
 * "automation:42") and never a bare id. Two producers number their rules
 * independently, so notification rule 42 and automation rule 42 on one
 * trigger and one occurrence hashed to one digest and the unique index
 * dropped whichever arrived second, with nothing anywhere reporting a
 * suppression. **This is a discontinuity in stored values**: a row written
 * before the qualifier keeps the digest it was written with and stays
 * readable, but the same rule and occurrence now hash differently, so rows
 * on either side of the change do not dedupe against each other. It landed
 * while the only queues holding rows were development ones.
 */
final class NotificationDigest
{
    public const PRODUCER_NOTIFICATION = 'notification';
    public const PRODUCER_AUTOMATION = 'automation';

    private function __construct()
    {
    }

    /**
     * The reference for a notification_rules row, and for the rule-less
     * occurrences (a sweep with no rule, a collapsed digest) that are still
     * the notification engine's own.
     */
    public static function notificationRuleRef(?int $ruleId): string
    {
        return self::PRODUCER_NOTIFICATION . ':' . ($ruleId ?? 0);
    }

    /** The reference for an automation_rules row. */
    public static function automationRuleRef(?int $ruleId): string
    {
        return self::PRODUCER_AUTOMATION . ':' . ($ruleId ?? 0);
    }

    public static function forIntent(
        string $producerRef,
        string $triggerKey,
        string $eventReference,
        string $occurrenceKey,
    ): string {
        return hash('sha256', implode('|', [
            'fanout',
            self::checked($producerRef),
            $triggerKey,
            $eventReference,
            $occurrenceKey,
        ]));
    }

    public static function forDelivery(
        string $producerRef,
        string $triggerKey,
        string $eventReference,
        string $templateKey,
        string $channelKey,
        int $recipientUserId,
        string $occurrenceKey,
    ): string {
        return hash('sha256', implode('|', [
            self::checked($producerRef),
            $triggerKey,
            $eventReference,
            $templateKey,
            $channelKey,
            (string) $recipientUserId,
            $occurrenceKey,
        ]));
    }

    /**
     * The digest item's own key: the delivery inputs plus the frequency,
     * because a replayed event with no subject would otherwise write two
     * identical items and nothing would notice.
     */
    public static function forDigestItem(
        string $producerRef,
        string $triggerKey,
        string $eventReference,
        string $categoryKey,
        string $channelKey,
        int $recipientUserId,
        string $occurrenceKey,
        string $frequency,
    ): string {
        return hash('sha256', implode('|', [
            self::checked($producerRef),
            $triggerKey,
            $eventReference,
            $categoryKey,
            $channelKey,
            (string) $recipientUserId,
            $occurrenceKey,
            $frequency,
        ]));
    }

    /** The per-attempt delivery record's digest, unchanged from before. */
    public static function forEmailLog(string $eventReference, string $templateKey, string $recipient): string
    {
        return hash('sha256', implode('|', [$eventReference, $templateKey, $recipient]));
    }

    /**
     * A suppression list stores an address digest and never the address, so
     * the list is not a second plaintext copy of the mailing list.
     */
    public static function forAddress(string $address): string
    {
        return hash('sha256', strtolower(trim($address)));
    }

    /**
     * A reference is refused rather than hashed when it names no producer,
     * because an unqualified id is exactly the collision this component
     * exists to prevent and a silently hashed one reintroduces it.
     */
    private static function checked(string $producerRef): string
    {
        if (preg_match('/^[a-z][a-z0-9_]*:\d+$/', $producerRef) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'A rule reference names its producer, such as "notification:42"; "%s" does not.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $producerRef,
            ));
        }

        return $producerRef;
    }
}
