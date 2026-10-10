<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Commerce;

/**
 * Access-source references and their unique lookup digests.
 *
 * Shop references identify the shop, order or subscription, customer, course
 * and grant revision. Reinstating revoked access creates a new revision while
 * keeping the old entitlement in the learner's history. Examples:
 * `woocommerce-order-42-user-60-course-7-r2` and
 * `easycommerce-subscription-9-user-60-course-7-r3`.
 *
 * Organisation references use the reserved `org{id}` prefix and identify a
 * membership or seat assignment. Every reference names exactly one course
 * and beneficiary. The digest uses SHA-256 over source + ":" + reference.
 */
final class SourceRef
{
    /** The separator between the source and the reference inside the digest. */
    public const DIGEST_SEPARATOR = ':';

    /**
     * The prefix the organisation family owns.
     *
     * A commerce source registering under a key that starts with it is
     * refused, because the whole point of binding the first segment to a
     * registered key is that the prefix has exactly one owner.
     */
    public const RESERVED_PREFIX = 'org';

    /** What a duplicate repair rewrites a losing key with, deliberately outside the grammar. */
    public const DUPLICATE_REPAIR_SUFFIX = '-dup';

    /** The declared width of `entitlements.source_ref`, which every minted reference stays inside. */
    public const MAX_LENGTH = 190;

    private function __construct()
    {
    }

    /**
     * The digest the unique index is carried on.
     *
     * The uniqueness is on this column and not on (source, source_ref)
     * directly, because source is string:30 and source_ref is string:190,
     * which is 880 bytes under utf8mb4 and past the 767-byte index key limit
     * of the legacy COMPACT and REDUNDANT InnoDB row formats. A failed CREATE
     * UNIQUE INDEX on such a host would land after a duplicate repair had
     * already revoked rows, which is the worst possible order.
     *
     * A null reference writes a null digest, which is what preserves the
     * manual and import paths' reliance on MySQL and SQLite treating nulls as
     * distinct in a unique index.
     */
    public static function digest(string $source, ?string $sourceRef): ?string
    {
        if ($sourceRef === null) {
            return null;
        }

        return hash('sha256', $source . self::DIGEST_SEPARATOR . $sourceRef);
    }

    /**
     * Composes a commerce-family reference.
     *
     * @param string     $sourceKey  a registered commerce source provider key
     * @param string     $objectType order, sub, gift, seat, and so on
     * @param int|string $objectId   the selling system's own identifier
     */
    public static function compose(
        string $sourceKey,
        string $objectType,
        int|string $objectId,
        int $courseId,
        int|string|null $itemId = null,
        ?int $beneficiaryUserId = null,
        int $reversalOrdinal = 0,
    ): string {
        self::requireUnreservedKey($sourceKey);

        if ($objectType === '' || (string) $objectId === '') {
            throw new \InvalidArgumentException('A source reference names the object type and its id.');
        }

        self::requirePlainSegment($objectType, 'object type');
        self::requirePlainSegment((string) $objectId, 'object id');

        if ($courseId < 1) {
            throw new \InvalidArgumentException(
                'A source reference always names the course, or a two-course order answers the second with the first.',
            );
        }

        if ($reversalOrdinal < 0) {
            throw new \InvalidArgumentException('A reversal ordinal is zero or positive.');
        }

        $ref = $sourceKey . '-' . $objectType . '-' . $objectId;

        if ($itemId !== null && (string) $itemId !== '') {
            $ref .= '-item-' . $itemId;
        }

        if ($beneficiaryUserId !== null) {
            if ($beneficiaryUserId < 1) {
                throw new \InvalidArgumentException('A named beneficiary is a positive user id.');
            }

            $ref .= '-user-' . $beneficiaryUserId;
        }

        $ref .= '-course-' . $courseId;

        if ($reversalOrdinal > 0) {
            $ref .= '-r' . $reversalOrdinal;
        }

        return $ref;
    }

    /**
     * Composes an organisation-family direct grant reference.
     *
     * The shipped shape verbatim, moved here so both families live in one
     * place rather than one of them living in a context object nobody would
     * think to look in.
     */
    public static function organisationGrant(
        int $organisationId,
        int $courseId,
        int $membershipId,
        int $organisationRevision,
    ): string {
        self::requirePositive($organisationId, 'organisation');
        self::requirePositive($courseId, 'course');
        self::requirePositive($membershipId, 'membership');

        if ($organisationRevision < 0) {
            throw new \InvalidArgumentException('An organisation revision is zero or positive.');
        }

        return self::RESERVED_PREFIX . $organisationId
            . ':course-' . $courseId
            . ':member-' . $membershipId
            . ':grant-' . $organisationRevision;
    }

    /**
     * Composes an organisation-family seat assignment reference.
     *
     * The assignment names exactly one person, which is how this shape
     * satisfies the beneficiary rule without a user segment.
     *
     * **The segment is `assignment_ref` and no longer the autoincrement id.**
     * The published grammar always said so and the code composed the id, which
     * is uniqueness by consequence rather than by construction: it holds only
     * while a reclaim nulls holder_key so the next assign inserts a fresh row.
     * The column exists now, is minted before the insert and carries its own
     * unique index, so the property is structural. Nothing is migrated and
     * nothing needs to be: this helper has exactly one caller, the grant, so a
     * reference is composed when a row is written and never to look one up,
     * and the refs already stored keep the shape they were written in.
     */
    public static function organisationSeat(int $organisationId, string $assignmentRef, int $courseId): string
    {
        self::requirePositive($organisationId, 'organisation');
        self::requirePositive($courseId, 'course');

        if (preg_match('/^[A-Za-z0-9]{1,64}$/D', $assignmentRef) !== 1) {
            throw new \InvalidArgumentException('An assignment reference is alphanumeric and at most 64 characters.');
        }

        return self::RESERVED_PREFIX . $organisationId
            . ':seat-assign-' . $assignmentRef
            . ':course-' . $courseId;
    }

    /** Whether a reference belongs to the organisation family. */
    public static function isOrganisationRef(string $sourceRef): bool
    {
        return preg_match('/^' . self::RESERVED_PREFIX . '[1-9][0-9]*:/', $sourceRef) === 1;
    }

    /**
     * The key a duplicate repair rewrites a losing row with.
     *
     * Deliberately outside both families, so no replay, no reversal ordinal
     * and no future bridge can ever match it again. The repair mutates the
     * key and not the status, because a unique index constrains column values
     * and not statuses: revoking the duplicates and then creating the index
     * fails on exactly the sites the repair exists for, after the repair has
     * already revoked rows.
     *
     * The base is trimmed so the suffix always survives. A reference already
     * at the column's declared width would otherwise have the suffix
     * truncated back onto the winner's key by a non-strict MySQL, which is
     * the one way this repair can produce the collision it exists to remove.
     * Two long references trimmed to the same prefix still differ, because
     * the entitlement id in the suffix is unique by construction.
     */
    public static function duplicateRepairRef(string $sourceRef, int $entitlementId): string
    {
        $suffix = self::DUPLICATE_REPAIR_SUFFIX . $entitlementId;
        $room = self::MAX_LENGTH - \strlen($suffix);

        if ($room < 1) {
            throw new \InvalidArgumentException('A duplicate repair suffix cannot be longer than the column.');
        }

        return substr($sourceRef, 0, $room) . $suffix;
    }

    /**
     * Refuses a commerce source key that would collide with the organisation
     * family.
     *
     * Called by compose() and available to a registry guard, so the reserved
     * prefix is enforced rather than described.
     */
    public static function requireUnreservedKey(string $sourceKey): void
    {
        if ($sourceKey === '') {
            throw new \InvalidArgumentException('A source reference opens with a registered commerce source key.');
        }

        if (preg_match('/^' . self::RESERVED_PREFIX . '[0-9]/', $sourceKey) === 1) {
            throw new \InvalidArgumentException(sprintf(
                'The "%s" prefix is reserved for organisation references; "%s" cannot be a commerce source key.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::RESERVED_PREFIX,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $sourceKey,
            ));
        }
    }

    /**
     * Refuses a segment value that would make the composed reference
     * ambiguous.
     *
     * Rule 2 is that a reference names its course, and a reference can only
     * name one course if the segments before it cannot themselves contain a
     * `-course-` run: an order id of `5-course-99` composes
     * `wc-order-5-course-99-course-7`, which no reader can take apart again.
     * The organisation family's colon is refused for the same reason, so a
     * composed commerce reference can never be mistaken for an organisation
     * one at the digest's first colon.
     */
    private static function requirePlainSegment(string $value, string $what): void
    {
        foreach (['-course-', '-item-', '-user-', ':'] as $reserved) {
            if (str_contains($value, $reserved)) {
                throw new \InvalidArgumentException(sprintf(
                    'A source reference %s cannot contain "%s"; it would name a second course or family.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $what,
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $reserved,
                ));
            }
        }
    }

    private static function requirePositive(int $value, string $what): void
    {
        if ($value < 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('A source reference needs a positive %s id.', $what));
        }
    }
}
