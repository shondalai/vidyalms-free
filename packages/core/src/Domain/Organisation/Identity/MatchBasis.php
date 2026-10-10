<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

/**
 * Which rule decided that an asserted identity is this account.
 *
 * Matching runs in a fixed order and never in another: the pair first, then
 * the connection's own external id, then an address the **host** has verified,
 * and never a display name. Every decision is recorded, because a duplicate
 * person is the documented top failure of this whole category and the question
 * it makes urgent, "why is this identity attached to that account", has no
 * other answer once the rows exist.
 *
 * pending_conflict is a decision too, and it is the one that matters most.
 * Two existing people are never merged automatically: the conflict raises a
 * row an administrator resolves through a route, because a merge is not
 * reversible and a machine cannot know which of two people the assertion meant.
 */
final class MatchBasis
{
    /** The connection's own immutable object id matched. */
    public const EXTERNAL_ID = 'matched_external_id';

    /** The (issuer, subject) pair matched, byte for byte. */
    public const SUBJECT = 'matched_subject';

    /** The host itself vouched for the address, or the organisation has verified its domain. */
    public const VERIFIED_EMAIL = 'matched_verified_email';

    /** An invitation the person proved they could receive. */
    public const INVITATION = 'matched_invitation';

    /** Nothing matched and the connection was allowed to create an account. */
    public const CREATED_BY_JIT = 'created_by_jit';

    /** Two candidates. Nobody is linked and an administrator resolves it. */
    public const PENDING_CONFLICT = 'pending_conflict';

    /**
     * The host handed the account over with the assertion, and nothing here
     * matched anything.
     *
     * It is the free build's own basis, and it is a seventh word rather than a
     * reuse of one of the six because none of the six is true of it. The free
     * host-group source asserts about an account the host has already
     * authenticated, so there was no matching rule at all; recording
     * matched_subject there said the pair had matched byte for byte about a
     * pair nobody had ever asserted before, which is the one thing an audit
     * reads this column to find out.
     */
    public const HOST_ACCOUNT = 'matched_host_account';

    /** @var list<string> */
    public const BASES = [
        self::EXTERNAL_ID,
        self::SUBJECT,
        self::VERIFIED_EMAIL,
        self::INVITATION,
        self::CREATED_BY_JIT,
        self::PENDING_CONFLICT,
        self::HOST_ACCOUNT,
    ];

    /**
     * The order the rules are tried in. It is published rather than implied,
     * because "the address is a hint and the pair is the key" is only true if
     * the pair is asked first.
     *
     * @var list<string>
     */
    public const ORDER = [self::SUBJECT, self::EXTERNAL_ID, self::VERIFIED_EMAIL, self::INVITATION];

    private function __construct()
    {
    }

    public static function isKnown(string $basis): bool
    {
        return \in_array($basis, self::BASES, true);
    }

    /**
     * The basis, or a refusal.
     *
     * There is no fallback word for "some other reason". A link whose reason
     * cannot be named is a link nobody can audit, and the vocabulary is closed
     * so that a third-party provisioning source cannot invent one.
     */
    public static function require(string $basis): string
    {
        if (!self::isKnown($basis)) {
            throw new \InvalidArgumentException(sprintf(
                'match_basis must be one of %s, not "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', self::BASES),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $basis
            ));
        }

        return $basis;
    }
}
