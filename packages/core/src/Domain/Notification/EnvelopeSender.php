<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Notification;

/**
 * The envelope sender, composed and taken apart in one place.
 *
 * The envelope sender is the SMTP MAIL FROM and not the From header. It is
 * where an asynchronous bounce is attributed from: a receiver that cannot
 * deliver reports to the reverse path, so a per message tag inside the
 * reverse path is what ties a report arriving hours later to the queue row
 * that caused it. That is VERP, and RFC 5233 subaddressing is the shape it
 * takes: a local part of a user part, a separator and a detail.
 *
 * It has to be a full address, and a bare tag is not one. PHPMailer validates
 * Sender inside preSend() and refuses the whole message when it fails, which
 * is how a bare tag turned every queued notification on both hosts into a
 * permanent failure that never reached a transport.
 *
 * The domain is never derived from the request host. A queue drained by a
 * scheduled task has no request, a console run would compose a different
 * domain from the same site, and a Host header is caller controlled. It comes
 * from provider settings for the channel, or from the host default the
 * adapter derives from the site's own from address.
 *
 * The limits are RFC 5321 section 4.5.3.1: a local part of at most 64 octets,
 * a domain of at most 255, and a reverse path of at most 256 characters
 * including the angle brackets. The last gate is PHP's own address validator,
 * because that is the validator PHPMailer uses unless a host replaces it and
 * the one WordPress replaces it with is stricter in the same direction, so an
 * address that passes both structural rules and that validator is one both
 * transports accept.
 */
final class EnvelopeSender
{
    /** Provider settings for the channel, resolved at site scope. */
    public const SETTING_DOMAIN = 'envelope_domain';
    public const SETTING_PREFIX = 'envelope_prefix';

    /** The user part of the reverse path when a site configures none. */
    public const DEFAULT_PREFIX = 'bounce';

    /** RFC 5233 calls it the separator; a plus is the one every MTA honours. */
    public const SEPARATOR = '+';

    /** The tag namespace, so a shared mailbox can tell our reports apart. */
    public const TAG_PREFIX = 'vy';

    public const REASON_DOMAIN_MISSING = 'envelope_domain_missing';
    public const REASON_DOMAIN_INVALID = 'envelope_domain_invalid';
    public const REASON_PREFIX_INVALID = 'envelope_prefix_invalid';
    public const REASON_TAG_INVALID = 'envelope_tag_invalid';
    public const REASON_TOO_LONG = 'envelope_sender_too_long';

    /** RFC 5321 section 4.5.3.1.1. */
    private const MAX_LOCAL_PART = 64;

    /** RFC 5321 section 4.5.3.1.2. */
    private const MAX_DOMAIN = 255;

    /** RFC 5321 section 4.5.3.1.3, less the two angle brackets. */
    private const MAX_PATH = 254;

    private function __construct(
        public readonly ?string $address,
        public readonly ?string $reason,
    ) {
    }

    /**
     * The tag for one queue row.
     *
     * The same string is what the channel reports back as the provider
     * message id, so a report correlates against `provider_message_id`
     * without a second lookup table.
     */
    public static function tagFor(int $queueId): string
    {
        return self::TAG_PREFIX . $queueId . '-' . bin2hex(random_bytes(16));
    }

    /**
     * A usable address, or the reason there is none.
     *
     * A site that configured a full address rather than a bare domain is
     * taken to have meant its domain, because that is the mistake the field
     * invites and refusing it would only cost the site its bounce
     * attribution.
     */
    public static function build(string $tag, string $domain, string $prefix = self::DEFAULT_PREFIX): self
    {
        // Refused whole rather than sanitised: a value carrying a line break
        // is not a domain someone typed badly, and taking the good half of
        // one would compose an address out of an injection attempt.
        if (strpbrk($domain, "\r\n") !== false) {
            return new self(null, self::REASON_DOMAIN_INVALID);
        }

        $domain = self::domainOf($domain);

        if ($domain === '') {
            return new self(null, self::REASON_DOMAIN_MISSING);
        }

        if (!self::isDomain($domain)) {
            return new self(null, self::REASON_DOMAIN_INVALID);
        }

        $prefix = trim($prefix);

        if (!self::isPart($prefix)) {
            return new self(null, self::REASON_PREFIX_INVALID);
        }

        $tag = trim($tag);

        if (!self::isPart($tag)) {
            return new self(null, self::REASON_TAG_INVALID);
        }

        $local = $prefix . self::SEPARATOR . $tag;
        $address = $local . '@' . $domain;

        if (\strlen($local) > self::MAX_LOCAL_PART || \strlen($address) > self::MAX_PATH) {
            return new self(null, self::REASON_TOO_LONG);
        }

        if (filter_var($address, \FILTER_VALIDATE_EMAIL) === false) {
            return new self(null, self::REASON_DOMAIN_INVALID);
        }

        return new self($address, null);
    }

    /**
     * The tag carried by an address, or null when it carries none.
     *
     * This is the half the feedback mapper needs: a report names the address
     * it was sent from, and the queue row is found from the tag inside it.
     * Everything after the first separator is the detail, per RFC 5233, so a
     * site that changed the prefix still correlates.
     */
    public static function tagOf(string $address): ?string
    {
        $address = trim($address, " \t<>");
        $at = strrpos($address, '@');

        if ($at === false || $at === 0) {
            return null;
        }

        $local = substr($address, 0, $at);
        $separator = strpos($local, self::SEPARATOR);

        if ($separator === false) {
            return null;
        }

        $tag = substr($local, $separator + 1);

        return $tag === '' ? null : $tag;
    }

    public function isUsable(): bool
    {
        return $this->address !== null;
    }

    /**
     * The domain of whatever the setting holds: a bare domain, an address, or
     * a domain someone typed an at sign in front of.
     */
    private static function domainOf(string $value): string
    {
        $value = trim($value, " \t<>");
        $at = strrpos($value, '@');

        if ($at !== false) {
            $value = substr($value, $at + 1);
        }

        return strtolower(rtrim(trim($value, " \t<>"), '.'));
    }

    /**
     * Two labels at least, because a dotless domain is not deliverable and
     * both hosts' validators refuse one. A non-ASCII domain is refused rather
     * than guessed at: punycode needs the intl extension, which the core may
     * not require.
     */
    private static function isDomain(string $domain): bool
    {
        if ($domain === '' || \strlen($domain) > self::MAX_DOMAIN) {
            return false;
        }

        $labels = explode('.', $domain);

        if (\count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        return preg_match('/^[a-z]{2,}$/', $labels[\count($labels) - 1]) === 1;
    }

    /**
     * A conservative dot-atom: the characters every MTA, every log parser and
     * both hosts' validators agree on, and no separator, so the tag stays
     * recoverable from the first one.
     */
    private static function isPart(string $part): bool
    {
        if ($part === '' || \strlen($part) >= self::MAX_LOCAL_PART) {
            return false;
        }

        if (str_starts_with($part, '.') || str_ends_with($part, '.') || str_contains($part, '..')) {
            return false;
        }

        return preg_match('/^[A-Za-z0-9._-]+$/', $part) === 1;
    }
}
