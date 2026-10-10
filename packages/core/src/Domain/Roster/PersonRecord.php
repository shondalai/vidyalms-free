<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Roster;

/**
 * One person, in the canonical shape no protocol touches.
 *
 * The middle of the anti-corruption mapping: a OneRoster `users.csv` row, a SCIM
 * user resource and a plain member CSV line all become one of these, and
 * everything downstream of the mapper reads this and never the foreign shape.
 * The prohibition is literal, and this class is where it is enforced by the
 * type system rather than by review: nothing here is a `sourcedId`, a
 * `userIds` array, an `externalId` or a claim name.
 *
 * ## The key is `externalId` and it is never the address
 *
 * OneRoster states that a `sourcedId`, once assigned, is permanently allocated
 * to that object and must not be reallocated, which is exactly the property an
 * idempotency key needs and exactly the property an email address does not
 * have: an address is reassignable, and it is optional in a conformant
 * `users.csv`. So `externalId` is the key and `email` is a hint used to find a
 * host account, in that order and never the other way round.
 */
final class PersonRecord
{
    /**
     * @param array<string, string|null> $attributes the canonical directory bag, already mapped
     */
    private function __construct(
        public readonly string $externalId,
        public readonly ?string $email,
        public readonly ?string $givenName,
        public readonly ?string $familyName,
        public readonly bool $enabled,
        public readonly array $attributes,
    ) {
    }

    /**
     * @param array<string, string|null> $attributes
     */
    public static function of(
        string $externalId,
        ?string $email = null,
        ?string $givenName = null,
        ?string $familyName = null,
        bool $enabled = true,
        array $attributes = [],
    ): self {
        return new self(
            trim($externalId),
            self::normalisedEmail($email),
            self::text($givenName),
            self::text($familyName),
            $enabled,
            array_filter($attributes, static fn (mixed $value): bool => $value !== null && $value !== ''),
        );
    }

    /** The display name, for a log line and nothing else. */
    public function displayName(): string
    {
        return trim(($this->givenName ?? '') . ' ' . ($this->familyName ?? ''));
    }

    /**
     * The address, lower-cased, which is the only form anything compares.
     *
     * Lower-casing the domain is required and lower-casing the local part is
     * not, per RFC 5321, and doing both anyway is what every host user table in
     * this product's reach actually does. Comparing an address case sensitively
     * against a host account would fail to find the person and then invite them
     * again, which is the one outcome a roster re-run must never produce.
     */
    private static function normalisedEmail(?string $email): ?string
    {
        $email = self::text($email);

        return $email === null ? null : mb_strtolower($email);
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
