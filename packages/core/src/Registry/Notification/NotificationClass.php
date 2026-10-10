<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

/**
 * What kind of message this is, and therefore what law applies to it.
 *
 * It is decided per template by the trigger provider that owns it and is
 * never settable by a caller at runtime, because the CAN-SPAM primary
 * purpose test asks what a reasonable recipient would conclude from the
 * subject line, which is a property of the message and not of the moment.
 *
 * It is deliberately not a column on notification_rules. Putting it there
 * makes it editable per rule, so a site author setting a marketing rule's
 * class to transactional bypasses consent, drops the unsubscribe headers and
 * defeats the Article 21(3) validation, which is three legal controls undone
 * by one form field.
 *
 * The class decides three things: whether the consent policy runs, whether
 * the unsubscribe header decorator adds the RFC 8058 pair, and whether the
 * admin matrix may offer "forced".
 */
enum NotificationClass: string
{
    case Transactional = 'transactional';
    case Operational = 'operational';
    case Optional = 'optional';
    case Marketing = 'marketing';

    /**
     * Transactional messages bypass preference and consent, exactly as a
     * password reset and a certificate must: they are not marketing and a
     * site may be obliged to send them.
     */
    public function bypassesPreference(): bool
    {
        return $this === self::Transactional;
    }

    public function requiresConsentCheck(): bool
    {
        return $this === self::Optional || $this === self::Marketing;
    }

    /**
     * Whether the RFC 8058 header pair is added. A channel that cannot carry
     * list headers may therefore not carry a non-transactional message.
     */
    public function requiresUnsubscribeHeaders(): bool
    {
        return $this !== self::Transactional;
    }

    /**
     * GDPR Article 21(3) says that on objection to direct marketing the data
     * shall no longer be processed for those purposes, with no balancing
     * available. So a forced policy state is refused at save for these two.
     */
    public function allowsForcedPolicy(): bool
    {
        return $this === self::Transactional || $this === self::Operational;
    }

    /**
     * Rank, so a rule can be refused for naming a class above its template's.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Marketing => 0,
            self::Optional => 1,
            self::Operational => 2,
            self::Transactional => 3,
        };
    }
}
