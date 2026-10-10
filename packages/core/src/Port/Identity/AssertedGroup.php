<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Identity;

/**
 * One group a host or a directory said this person belongs to.
 *
 * A key, the kind of key it is, and an optional label for a screen. Nothing
 * else crosses: no directory object, no claim, no membership resource.
 *
 * The label is display only and is never compared. A rule that matched on a
 * label would be a rule that breaks when somebody renames a group, and the
 * whole point of carrying the kind beside the key is that a rule author knows
 * which of the two they are matching.
 */
final class AssertedGroup
{
    private function __construct(
        public readonly string $key,
        public readonly string $keyKind,
        public readonly ?string $label,
    ) {
        if (trim($key) === '') {
            throw new \InvalidArgumentException('An asserted group needs a key.');
        }
    }

    public static function of(string $key, string $keyKind, ?string $label = null): self
    {
        return new self($key, AssertedKeyKind::require($keyKind, 'group key_kind'), $label);
    }

    /** A host group or role, which is always an identifier on both shipped hosts. */
    public static function hostGroup(string $key, ?string $label = null): self
    {
        return new self($key, AssertedKeyKind::ID, $label);
    }

    /**
     * Whether this group is the same kind of thing as a rule's value.
     *
     * Comparing an id against a name is not a mismatch that produces false:
     * it is a comparison that should never have been attempted, and the
     * evaluator records it as a rule that did not apply rather than as a rule
     * that failed.
     */
    public function isKind(string $keyKind): bool
    {
        return $this->keyKind === $keyKind;
    }
}
