<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Identity;

/**
 * What the assertion said about the address, as three answers and not two.
 *
 * A boolean cannot carry the difference between "the issuer said this address
 * is verified", "the issuer said it is not" and "the issuer did not say", and
 * the third is the common case: most assertions carry no verification claim at
 * all. Collapsing unknown onto false makes an ordinary assertion look like a
 * denial; collapsing it onto true is the hole this whole design exists to
 * close.
 *
 * None of the three is ever sufficient on its own. This is the issuer, from a
 * directory the tenant controls, describing an address; the host-verified
 * answer comes from VerifiedAddressesPort or from an organisation's own
 * verified domain, which is a different party saying it.
 */
final class EmailVerification
{
    /** The issuer claimed the address is verified. */
    public const VERIFIED = 'true';

    /** The issuer claimed the address is not verified. */
    public const UNVERIFIED = 'false';

    /** The issuer said nothing about it, which is neither of the above. */
    public const UNKNOWN = 'unknown';

    /** @var list<string> */
    public const VALUES = [self::VERIFIED, self::UNVERIFIED, self::UNKNOWN];

    private function __construct()
    {
    }

    public static function isKnown(string $value): bool
    {
        return \in_array($value, self::VALUES, true);
    }

    public static function require(string $value, string $field = 'email_verified'): string
    {
        if (!self::isKnown($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s must be one of %s, not "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $field,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', self::VALUES),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $value
            ));
        }

        return $value;
    }

    /**
     * The tri-state a boolean caller means.
     *
     * True means the issuer said so. False means the issuer's claim was
     * absent or negative and the two are not distinguished from a boolean, so
     * a caller that has the difference must pass the word rather than the
     * flag.
     */
    public static function fromFlag(bool $verified): string
    {
        return $verified ? self::VERIFIED : self::UNKNOWN;
    }
}
