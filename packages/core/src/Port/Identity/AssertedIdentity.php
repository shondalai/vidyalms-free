<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Identity;

use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IdentityKey;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * What an identity provider or a host said about the person signing in,
 * expressed in the core's own vocabulary and nothing else.
 *
 * This is the canonical shape every identity mapper produces and no protocol
 * touches: an attribute statement, a claim set, a directory resource and a
 * plain host group list all arrive here and stop being protocol.
 *
 * **Identity is keyed on issuer and subject, compared byte for byte.** Not on
 * an address: an address is reassignable, is asserted by the party whose
 * assertion is being checked, and a decade of authentication bypasses came
 * from treating one as a key. There is no unique index on any address
 * anywhere, and a membership with no address at all is a supported state.
 *
 * emailVerified is what the issuer claimed, and it is never sufficient on its
 * own. The host-verified answer comes from VerifiedAddressesPort or from an
 * organisation's own verified domain, which is a different party saying it.
 *
 * **The value object is versioned**, because the mapped bag and the decision
 * that came from it are stored: the last-asserted record keeps the canonical
 * attributes and the mapping decision, never the raw payload, and a stored row
 * has to say which shape wrote it. {@see SHAPE_VERSION} is that number and
 * {@see toStored()} is the only writer of it.
 */
final class AssertedIdentity
{
    /**
     * The stored shape of the minimised record.
     *
     * One, because this is the first shape. It is bumped when a field changes
     * meaning rather than when one is added, on StoredShape's own terms: a
     * reader handed version 0 is reading a row written before the envelope
     * existed, which for this class means no row at all today.
     */
    public const SHAPE_VERSION = 1;

    /**
     * @param list<string>         $groups        raw group keys, exactly as asserted
     * @param array<string, mixed> $attributes    the canonical bag, keyed by the core vocabulary
     * @param list<AssertedGroup>  $assertedGroups the same groups carrying the kind of key each one is
     */
    private function __construct(
        public readonly string $issuer,
        public readonly string $subject,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly array $groups,
        public readonly array $attributes,
        /**
         * Whether the source could say everything it knows.
         *
         * A partial source may add and refresh and may never remove: an
         * absent group claim is silence and not a denial, which is what a
         * directory that truncates its group claim past a cap produces.
         */
        public readonly bool $complete,
        /** One of AssertedCompleteness::VALUES, of which $complete is the boolean face. */
        public readonly string $completeness = AssertedCompleteness::COMPLETE,
        /** One of EmailVerification::VALUES, of which $emailVerified is the boolean face. */
        public readonly string $emailVerification = EmailVerification::UNKNOWN,
        /** When the assertion was made, as the issuer stated it. Null when it did not. */
        public readonly ?\DateTimeImmutable $assertedAt = null,
        /**
         * The session handle the issuer named, for logout correlation only.
         *
         * It is opaque and is never parsed. The core does not terminate a
         * session protocol; it invalidates its own cached identity and its own
         * tokens for a principal, which is all a relying party can honestly do.
         */
        public readonly ?string $sessionRef = null,
        /**
         * The assertion's own identifier, for provisioning idempotency.
         *
         * Even where a host correctly rejects a replayed assertion, a consumer
         * that is not itself idempotent double-writes a membership and spends
         * a second seat on a retried delivery.
         */
        public readonly ?string $assertionRef = null,
        public readonly array $assertedGroups = [],
    ) {
        if (trim($issuer) === '' || trim($subject) === '') {
            throw new \InvalidArgumentException('An asserted identity needs both an issuer and a subject.');
        }

        self::refuseControlBytes('issuer', $issuer);
        self::refuseControlBytes('subject', $subject);
    }

    /**
     * The one shape check either half gets, and the reason it is here.
     *
     * The subject is opaque and stays opaque: nothing trims it, folds its case
     * or normalises it, because a subject that a document traversal returned
     * differently from the one a signature covered has to compare unequal. A
     * control byte is the exception, and it is refused rather than tolerated,
     * because the two halves are concatenated into one stored key and a
     * separator byte inside a half is the one thing that makes two distinct
     * identities indistinguishable. No protocol termination emits one; a
     * caller that hands one over is handing over a crafted value.
     */
    private static function refuseControlBytes(string $half, string $value): void
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value) !== 1) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'An asserted %s carries a control byte. Identity is keyed on the pair, byte exact, and a'
                . ' separator byte inside one half is how two different identities become one key.',
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $half,
        ));
    }

    /**
     * @param list<string>         $groups
     * @param array<string, mixed> $attributes
     */
    public static function create(
        string $issuer,
        string $subject,
        ?string $email = null,
        bool $emailVerified = false,
        array $groups = [],
        array $attributes = [],
        bool $complete = true,
    ): self {
        return new self(
            $issuer,
            $subject,
            $email,
            $emailVerified,
            $groups,
            $attributes,
            $complete,
            AssertedCompleteness::fromComplete($complete),
            EmailVerification::fromFlag($emailVerified),
            null,
            null,
            null,
            array_map(
                static fn (string $key): AssertedGroup => AssertedGroup::of($key, AssertedKeyKind::NAME),
                array_values($groups)
            ),
        );
    }

    /**
     * The full shape, for a mapper that knows the kind of every key it holds.
     *
     * It is a second named factory rather than seven more defaulted arguments
     * on the first, because create() is the shape shipped before this wave and
     * a caller written against it keeps compiling and keeps meaning what it
     * meant. The two agree on every field they share.
     *
     * @param list<AssertedGroup>  $groups
     * @param array<string, mixed> $attributes
     */
    public static function asserted(
        string $issuer,
        string $subject,
        array $groups = [],
        array $attributes = [],
        string $completeness = AssertedCompleteness::COMPLETE,
        ?string $email = null,
        string $emailVerification = EmailVerification::UNKNOWN,
        ?\DateTimeImmutable $assertedAt = null,
        ?string $sessionRef = null,
        ?string $assertionRef = null,
    ): self {
        $completeness = AssertedCompleteness::require($completeness);
        $emailVerification = EmailVerification::require($emailVerification);

        return new self(
            $issuer,
            $subject,
            $email,
            $emailVerification === EmailVerification::VERIFIED,
            array_values(array_map(static fn (AssertedGroup $group): string => $group->key, $groups)),
            $attributes,
            $completeness === AssertedCompleteness::COMPLETE,
            $completeness,
            $emailVerification,
            $assertedAt,
            $sessionRef,
            $assertionRef,
            array_values($groups),
        );
    }

    /**
     * The stable key for this identity: the pair, never the address.
     *
     * It composes through {@see IdentityKey::compose()} rather than joining on
     * a separator of its own, so this and the stored digest cannot disagree
     * about what "the pair" is.
     */
    public function key(): string
    {
        return IdentityKey::compose($this->issuer, $this->subject);
    }

    /** Whether this source may drive a removal at all, before any connection is consulted. */
    public function isComplete(): bool
    {
        return $this->completeness === AssertedCompleteness::COMPLETE;
    }

    /**
     * The groups carrying their key kinds.
     *
     * A caller built through create() gets name-kinded groups, which is the
     * conservative reading: a bare string from a host that did not say what it
     * was is not an identifier, and a rule matching on ids will not match it.
     *
     * @return list<AssertedGroup>
     */
    public function assertedGroups(): array
    {
        return $this->assertedGroups;
    }

    /**
     * The minimised record: the canonical bag and nothing raw.
     *
     * The raw assertion is deliberately absent. This is a second copy of
     * directory data the customer already holds, so its justification is
     * reconciliation and audit, and that justification does not reach the
     * payload the mapper read.
     *
     * @return array<string, mixed>
     */
    public function toStored(): array
    {
        return StoredShape::wrap([
            'issuer' => $this->issuer,
            'subject' => $this->subject,
            'email' => $this->email,
            'email_verified' => $this->emailVerification,
            'completeness' => $this->completeness,
            'asserted_at' => $this->assertedAt?->format('Y-m-d H:i:s'),
            'session_ref' => $this->sessionRef,
            'assertion_ref' => $this->assertionRef,
            'groups' => array_map(
                static fn (AssertedGroup $group): array => [
                    'key' => $group->key,
                    'key_kind' => $group->keyKind,
                    'label' => $group->label,
                ],
                $this->assertedGroups
            ),
            'attributes' => $this->attributes,
        ], self::SHAPE_VERSION);
    }
}
