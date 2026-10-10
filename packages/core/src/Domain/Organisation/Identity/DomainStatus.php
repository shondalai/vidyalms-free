<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

/**
 * The state machine a domain claim moves through.
 *
 * The same four words the issuer claim uses, and deliberately the same, because
 * they are the same ceremony over two different namespaces: the domain says
 * which organisation an address belongs to, the issuer says who signed for the
 * person, and neither alone is sufficient.
 *
 * **Verification is not a one-time event.** A domain whose proof record is
 * deleted or changed loses verified status, and every policy that depended on
 * it stops applying. That is why the re-check sweep exists, why
 * last_checked_at and failure_count are real columns, and why verified is not
 * terminal.
 *
 * The free build records domains and shows them unverified. That is the
 * registry-miss contract in force, not a padlock: the row exists, the token
 * exists, the screen says nobody can prove it yet, and the day a verifier is
 * installed the same row is provable with no migration.
 */
final class DomainStatus
{
    public const PENDING = 'pending';
    public const VERIFIED = 'verified';
    public const FAILED = 'failed';
    public const REVOKED = 'revoked';

    /** @var list<string> */
    public const STATUSES = [self::PENDING, self::VERIFIED, self::FAILED, self::REVOKED];

    /** @var array<string, list<string>> */
    public const EDGES = [
        self::PENDING => [self::VERIFIED, self::FAILED, self::REVOKED],
        self::FAILED => [self::PENDING, self::VERIFIED, self::REVOKED],
        self::VERIFIED => [self::FAILED, self::REVOKED],
        self::REVOKED => [],
    ];

    /**
     * The verification methods THIS BUILD knows how to describe.
     *
     * Two rather than one because a site that cannot publish a name record
     * can publish a file, and because publishing a dedicated host record
     * rather than the apex is what stops several products' verifications
     * colliding on one apex record.
     *
     * **It is not the closed vocabulary of what a proof may be.** A package
     * proving a domain by a registrar API, an MX record or a postmaster
     * mailbox names its own method, and the row keeps that word: the two
     * enforcement points used to disagree, one refusing an unknown method and
     * the other coercing it to `dns-txt`, so a proof obtained by another means
     * could be recorded as a DNS TXT proof that was never published. The claim
     * surface still offers these two, because they are the two this build can
     * tell an operator how to publish.
     *
     * @var list<string>
     */
    public const METHODS = ['dns-txt', 'http-file'];

    /**
     * The settings row holding the public mailbox domains no organisation may
     * claim.
     *
     * A settings row rather than a column, on the precedent for site-wide
     * operational values that no provider interprets and no report filters on,
     * and rather than a compiled constant because the list of public mailbox
     * providers changes and an operator should not need a release to add one.
     */
    public const EXCLUSION_SETTING = 'organisation.public_mailbox_domains';

    /**
     * The shipped exclusion list.
     *
     * Claiming one of these would let one organisation auto-admit every person
     * on the site whose address happens to be at a consumer mail provider,
     * which is the whole hole the join rule row exists to close.
     *
     * @var list<string>
     */
    public const DEFAULT_EXCLUSIONS = [
        'gmail.com',
        'googlemail.com',
        'outlook.com',
        'hotmail.com',
        'live.com',
        'msn.com',
        'yahoo.com',
        'yahoo.co.uk',
        'ymail.com',
        'aol.com',
        'icloud.com',
        'me.com',
        'mac.com',
        'proton.me',
        'protonmail.com',
        'gmx.com',
        'gmx.net',
        'mail.com',
        'zoho.com',
        'yandex.com',
        'qq.com',
        '163.com',
        '126.com',
        'rediffmail.com',
    ];

    private function __construct()
    {
    }

    public static function isKnown(string $status): bool
    {
        return \in_array($status, self::STATUSES, true);
    }

    public static function permits(string $from, string $to): bool
    {
        return \in_array($to, self::EDGES[$from] ?? [], true);
    }

    public static function isMethod(string $method): bool
    {
        return \in_array($method, self::METHODS, true);
    }

    /** The domain while verified, null otherwise, computed in one place. */
    public static function verifiedKeyFor(string $status, string $domain): ?string
    {
        return $status === self::VERIFIED ? $domain : null;
    }

    /**
     * The domain as it is stored and compared: trimmed, lower-cased, with a
     * trailing dot and a leading protocol or "@" removed.
     *
     * Normalising in one place is what makes the unique index mean what it
     * says. A claim on "Example.COM." and a claim on "example.com" are one
     * claim, and letting both exist would let two organisations each hold one
     * of them verified.
     */
    public static function normalise(string $domain): string
    {
        $value = strtolower(trim($domain));
        $value = preg_replace('#^[a-z]+://#', '', $value) ?? $value;
        $at = strrpos($value, '@');

        if ($at !== false) {
            $value = substr($value, $at + 1);
        }

        return rtrim($value, '.');
    }

    /** Whether this is a shape a domain claim may be made on at all. */
    public static function isClaimable(string $domain): bool
    {
        if ($domain === '' || \strlen($domain) > 190) {
            return false;
        }

        // A label of letters, digits and hyphens, at least two labels, and no
        // leading or trailing hyphen in any of them. Deliberately strict: a
        // claim is compared byte for byte against a proof somebody publishes,
        // so anything this cannot express is a claim nobody could ever prove.
        return preg_match('/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.(?!-)[a-z0-9-]{1,63}(?<!-))+$/', $domain) === 1;
    }
}
