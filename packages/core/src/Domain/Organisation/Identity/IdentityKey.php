<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

/**
 * The byte-exact key for one asserted identity, computed in exactly one place.
 *
 * The design asks for a unique index on (issuer, subject) under a binary
 * collation, so that two subjects differing only in bytes a text collation
 * folds together are two identities. Two things stop that being written
 * literally, and the digest answers both.
 *
 * **Width.** (issuer, subject) is 190 plus 255 characters, which is 1780 bytes
 * under utf8mb4 and 1016 even with the subject hashed. The schema's own width
 * assertion refuses both and names the remedy in its message: carry the
 * uniqueness on a sha256 digest column. That is the same device
 * entitlements.source_ref_digest already uses, for the same reason, and the
 * assertion that refused this index is the shipped rule rather than a
 * preference.
 *
 * **Collation.** A digest is byte exact whatever collation the host installed
 * the table with, which matters here because the two shipped hosts create
 * their tables through two different mechanisms and a third adapter would use
 * a third. A binary collation declared in one installer and forgotten in
 * another is a guarantee that holds on one host.
 *
 * The issuer and the subject are stored beside the key, unchanged, because an
 * administrator looking at a link has to be able to read what was asserted.
 * Nothing ever compares those columns.
 */
final class IdentityKey
{
    /**
     * The separator between the two halves.
     *
     * A NUL rather than a colon, because an issuer or a subject may contain a
     * colon and the concatenation would then be ambiguous: issuer "a:b" with
     * subject "c" and issuer "a" with subject "b:c" would be one key and one
     * identity.
     *
     * The separator alone is not enough, and this class used to say it was. The
     * claim was that "a NUL cannot appear in either half of anything a protocol
     * termination hands over", and nothing anywhere checked it: the subject is
     * deliberately opaque and AssertedIdentity only trimmed and required
     * non-empty. A subject of "alice\0bob" under issuer I and a subject of "bob"
     * under issuer "I\0alice" are two genuinely different identities that
     * produced one digest, so the second of them collided on the unique index
     * the design's own (issuer, subject) index could not have collided on.
     * {@see compose()} carries the length of the first half, which makes the
     * concatenation unambiguous whatever bytes either half holds, and
     * {@see AssertedIdentity} now refuses a control byte at the boundary as
     * well. Both, because the digest is also composed for callers that never
     * passed through an assertion.
     */
    public const SEPARATOR = "\0";

    private function __construct()
    {
    }

    /**
     * The unambiguous concatenation of the two halves.
     *
     * The length of the issuer, a colon, the issuer, the separator, the
     * subject. A reader can recover both halves from it, which is the property
     * that makes two distinct pairs two distinct strings.
     */
    public static function compose(string $issuer, string $subject): string
    {
        return \strlen($issuer) . ':' . $issuer . self::SEPARATOR . $subject;
    }

    /**
     * The key for a pair.
     *
     * No trimming, no case folding, no normalisation of any kind on either
     * half. The subject a library hands us is treated as opaque and compared
     * byte for byte, which is the mitigation for the family of defects where a
     * document traversal returned a different subject string than the one a
     * signature actually covered. Trimming here would undo it.
     */
    public static function of(string $issuer, string $subject): string
    {
        return hash('sha256', self::compose($issuer, $subject));
    }
}
