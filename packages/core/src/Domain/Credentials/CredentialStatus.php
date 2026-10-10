<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * What a credential is right now, as one of six values rather than as a
 * revocation date.
 *
 * Two of the six are derived from the clock and never stored, so a clock
 * change cannot leave rows stale: a credential is expired because its
 * expires_at has passed, not because a sweep got round to writing it down.
 * Bitstring Status List v1.0 names suspension as a first-class reversible
 * purpose beside revocation, and a boolean revoked_at cannot express the
 * temporary hold a payment dispute, a plagiarism investigation or a lapsed CE
 * registration actually needs.
 *
 * Because the value is single valued while a stored and a derived status can
 * both apply, the precedence is published here rather than left to whichever
 * the implementation happens to compute first. A certificate revoked for
 * plagiarism in 2026 whose expiry passes in 2028 reports revoked, not
 * expired, because "lapsed" and "withdrawn" are materially different answers
 * to a relying party. The verification payload carries revoked and expired as
 * independent booleans beside this, so the claim that the two axes are
 * independent is true of the payload and not only of the prose.
 */
enum CredentialStatus: string
{
    case Active = 'active';
    case NotYetValid = 'not_yet_valid';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Revoked = 'revoked';
    case Superseded = 'superseded';

    /**
     * The values a row may actually hold in its status column. The other two
     * are answers, never records.
     *
     * @return list<string>
     */
    public static function storedValues(): array
    {
        return [self::Active->value, self::Suspended->value, self::Revoked->value, self::Superseded->value];
    }

    /**
     * Every value the admin filter offers, which is all six.
     *
     * @return list<string>
     */
    public static function allValues(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The stored column as a status, with the unknown case failing closed.
     *
     * It used to answer active for anything it did not recognise, which is a
     * fail-open default on the single column that governs whether a
     * credential is in force: a typo, a value written by a third-party
     * package, or one of the two derived values accidentally persisted all
     * read back as a credential in force. Every other registry miss in this
     * design either fails closed or fails visible, and this is the one place
     * where failing open puts a withdrawn credential back into circulation.
     * An unrecognised value is a reversible hold, which is the answer a site
     * can correct by reinstating.
     *
     * Null and the empty string are deliberately not in that set, and the
     * reason is the schema: status is declared NOT NULL with a default of
     * active, so an absent value cannot arise from a conforming write. It
     * means the row predates the column rather than that somebody set an
     * unknown status, and reading it as held would withdraw every credential
     * a site issued before the migration. That distinction is pinned by
     * CredentialStatusVocabularyTest rather than left to a reader.
     */
    public static function fromStored(?string $stored): self
    {
        return match ($stored) {
            self::Active->value, null, '' => self::Active,
            self::Suspended->value => self::Suspended,
            self::Revoked->value => self::Revoked,
            self::Superseded->value => self::Superseded,
            default => self::Suspended,
        };
    }

    /**
     * The published precedence, in one place: revoked, then superseded, then
     * suspended, then not yet valid, then expired, then active.
     */
    public static function resolve(
        self $stored,
        ?\DateTimeImmutable $validFrom,
        ?\DateTimeImmutable $expiresAt,
        \DateTimeImmutable $now,
    ): self {
        if ($stored === self::Revoked) {
            return self::Revoked;
        }

        if ($stored === self::Superseded) {
            return self::Superseded;
        }

        if ($stored === self::Suspended) {
            return self::Suspended;
        }

        if ($validFrom !== null && $validFrom > $now) {
            return self::NotYetValid;
        }

        if ($expiresAt !== null && $expiresAt <= $now) {
            return self::Expired;
        }

        return self::Active;
    }

    /**
     * Whether the credential is in force. Derived, and reported beside the
     * status rather than instead of it.
     */
    public function isValid(): bool
    {
        return $this === self::Active;
    }

    /**
     * Whether this status keeps the row holding its enrolment in the
     * exactly-once guard column. Superseded and revoked release it, which is
     * what lets a replacement be issued for the same enrolment; suspended and
     * not-yet-valid hold it, because neither entitles the learner to a second
     * certificate.
     */
    public function holdsActiveKey(): bool
    {
        return $this === self::Active || $this === self::Suspended || $this === self::NotYetValid;
    }

    /**
     * The verification reason this status carries, or null when the
     * credential is in force.
     */
    public function reason(): ?string
    {
        return match ($this) {
            self::Active => null,
            self::NotYetValid => 'not_yet_valid',
            self::Expired => 'expired',
            self::Suspended => 'suspended',
            self::Revoked => 'revoked',
            self::Superseded => 'superseded',
        };
    }
}
