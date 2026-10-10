<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

/**
 * The state machine an issuer claim moves through, and the edges it may move
 * along.
 *
 * Four states and eight edges. The interesting rules are not the states but
 * what each one permits:
 *
 * - **only verified drives anything.** Mapping rule evaluation, just-in-time
 *   provisioning and every directory write are refused against a connection
 *   whose issuer is not verified, so an unverified row is inert rather than
 *   dangerous, and an assertion resolves the one verified connection rather
 *   than every row that named the issuer;
 * - **only a site-scoped principal moves a connection to verified**, and the
 *   transition is written to the membership event stream with the actor;
 * - and at most one organisation holds an issuer verified at a time, which the
 *   database enforces through the global unique index on verified_issuer_key
 *   rather than a use case checking first.
 *
 * revoked is terminal on purpose. A claim that was withdrawn is evidence, and
 * re-claiming an issuer is a fresh row with a fresh ceremony rather than a
 * revoked row quietly coming back.
 */
final class IssuerStatus
{
    /** Claimed, proved by nobody, and driving nothing. */
    public const PENDING = 'pending';

    /** Proved. At most one organisation holds this for any one issuer. */
    public const VERIFIED = 'verified';

    /** A verification was attempted and did not hold. */
    public const FAILED = 'failed';

    /** Withdrawn. Terminal. */
    public const REVOKED = 'revoked';

    /** @var list<string> */
    public const STATUSES = [self::PENDING, self::VERIFIED, self::FAILED, self::REVOKED];

    /** @var list<string> */
    public const TERMINAL = [self::REVOKED];

    /**
     * The edges, as from to a list of tos.
     *
     * @var array<string, list<string>>
     */
    public const EDGES = [
        self::PENDING => [self::VERIFIED, self::FAILED, self::REVOKED],
        self::FAILED => [self::PENDING, self::VERIFIED, self::REVOKED],
        // A verified claim can be demoted by the re-check, which is what makes
        // verification a state rather than an event.
        self::VERIFIED => [self::FAILED, self::REVOKED],
        self::REVOKED => [],
    ];

    private function __construct()
    {
    }

    public static function isKnown(string $status): bool
    {
        return \in_array($status, self::STATUSES, true);
    }

    /** Whether this transition is one the machine has. An unknown state moves nowhere. */
    public static function permits(string $from, string $to): bool
    {
        return \in_array($to, self::EDGES[$from] ?? [], true);
    }

    /** Whether a connection in this state may drive a rule, a creation or a write. */
    public static function drivesProvisioning(string $status): bool
    {
        return $status === self::VERIFIED;
    }

    /**
     * The value that goes in verified_issuer_key for this state.
     *
     * The issuer while verified, and null otherwise. It is computed in one
     * place because two writers computing it differently is exactly how two
     * organisations end up holding one issuer verified.
     */
    public static function verifiedKeyFor(string $status, string $issuer): ?string
    {
        return $status === self::VERIFIED ? $issuer : null;
    }
}
