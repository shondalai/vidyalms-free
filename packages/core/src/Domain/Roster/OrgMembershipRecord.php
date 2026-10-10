<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Roster;

use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipState;

/**
 * One person's relationship to one organisation or unit, as the source states
 * it.
 *
 * ## The status vocabulary, and the one word that deletes
 *
 * OneRoster's `status` is `active` or `tobedeleted`, and **the tombstone is the
 * only deletion instruction the free build honours**: the row stays in the file
 * with `status = tobedeleted` and a final `dateLastModified`, and the consumer
 * decides when or whether to delete physically. Canvas's `inactive` and
 * `completed` are accepted beside them, because visible but not participating
 * is a real state an org admin needs and neither `active` nor `tobedeleted`
 * says it.
 *
 * **A row simply missing from a later file causes nothing.** That rule lives in
 * the importer rather than here, because a record that does not exist cannot
 * carry it, but it is stated here too: this class has no word for "gone", only
 * for "the source says to remove this".
 *
 * ## The role token is foreign and stays foreign
 *
 * `roleToken` is the source's own word: a OneRoster role, an LTI URI, a Canvas
 * role_id. It is translated by a registered OrgRoleMapper and never here, so a
 * customer whose district encodes its own words registers a mapper instead of
 * asking for a column.
 */
final class OrgMembershipRecord
{
    /** The source says this relationship is current. */
    public const STATUS_ACTIVE = 'active';

    /** The tombstone. The one and only deletion instruction. */
    public const STATUS_TO_BE_DELETED = 'tobedeleted';

    /** On the roster, not participating: the person moved on. */
    public const STATUS_INACTIVE = 'inactive';

    /** On the roster, not participating: the programme ended. */
    public const STATUS_COMPLETED = 'completed';

    /** @var list<string> */
    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_TO_BE_DELETED,
        self::STATUS_INACTIVE,
        self::STATUS_COMPLETED,
    ];

    /**
     * The membership state each source status becomes.
     *
     * One map rather than a conditional per caller, because the roster path and
     * the provisioning path have to agree and a second copy of this is the
     * first place they would stop agreeing.
     *
     * @var array<string, string>
     */
    private const STATES = [
        self::STATUS_ACTIVE => MembershipState::ACTIVE,
        self::STATUS_TO_BE_DELETED => MembershipState::LEFT,
        self::STATUS_INACTIVE => MembershipState::INACTIVE,
        self::STATUS_COMPLETED => MembershipState::COMPLETED,
    ];

    private function __construct(
        public readonly string $externalId,
        public readonly string $personExternalId,
        public readonly ?string $orgExternalId,
        public readonly ?string $roleToken,
        public readonly string $status,
        public readonly bool $primary,
        public readonly ?string $beginsAt,
        public readonly ?string $endsAt,
        public readonly ?string $modifiedAt,
        public readonly ?string $managerEmail,
        public readonly ?string $unitPath,
    ) {
    }

    public static function of(
        string $externalId,
        string $personExternalId,
        ?string $orgExternalId = null,
        ?string $roleToken = null,
        string $status = self::STATUS_ACTIVE,
        bool $primary = false,
        ?string $beginsAt = null,
        ?string $endsAt = null,
        ?string $modifiedAt = null,
        ?string $managerEmail = null,
        ?string $unitPath = null,
    ): self {
        return new self(
            trim($externalId),
            trim($personExternalId),
            self::text($orgExternalId),
            self::text($roleToken),
            $status,
            $primary,
            self::text($beginsAt),
            self::text($endsAt),
            self::text($modifiedAt),
            self::text($managerEmail),
            self::text($unitPath),
        );
    }

    /** Whether this word is one the free build understands. */
    public static function isKnownStatus(string $status): bool
    {
        return \in_array($status, self::STATUSES, true);
    }

    /** Whether the source is instructing a removal, which only the tombstone does. */
    public function isTombstone(): bool
    {
        return $this->status === self::STATUS_TO_BE_DELETED;
    }

    /** The membership state this record asks for. */
    public function membershipState(): string
    {
        return self::STATES[$this->status] ?? MembershipState::ACTIVE;
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
