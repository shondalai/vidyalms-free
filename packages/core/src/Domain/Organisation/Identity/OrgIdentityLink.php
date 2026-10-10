<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

/**
 * One asserted identity attached to one host account, as a read model.
 *
 * There is no address on it, because there is no address column. That is the
 * single structural answer to the whole family of defects where a directory
 * load matched people by their addresses and created duplicates that nobody
 * could afterwards untangle, and it is what lets a membership carrying no
 * address at all be an ordinary state.
 */
final class OrgIdentityLink
{
    private function __construct(
        public readonly int $id,
        public readonly ?int $organisationId,
        public readonly ?int $connectionId,
        public readonly string $issuer,
        public readonly string $subject,
        public readonly int $userId,
        public readonly ?string $externalId,
        public readonly string $matchBasis,
        public readonly ?string $matchRef,
        public readonly string $state,
        public readonly ?string $firstSeenAt,
        public readonly ?string $lastAssertedAt,
    ) {
    }

    public static function fromRow(
        int $id,
        ?int $organisationId,
        ?int $connectionId,
        string $issuer,
        string $subject,
        int $userId,
        ?string $externalId,
        string $matchBasis,
        ?string $matchRef,
        string $state,
        ?string $firstSeenAt,
        ?string $lastAssertedAt,
    ): self {
        return new self(
            $id,
            $organisationId,
            $connectionId,
            $issuer,
            $subject,
            $userId,
            $externalId,
            MatchBasis::isKnown($matchBasis) ? $matchBasis : MatchBasis::PENDING_CONFLICT,
            $matchRef,
            IdentityLinkState::isKnown($state) ? $state : IdentityLinkState::CONFLICT,
            $firstSeenAt,
            $lastAssertedAt,
        );
    }

    /**
     * Whether this link actually says who somebody is.
     *
     * A pending link does not, and neither does a conflicted one. A caller
     * that treated either as an answer would be doing the thing the pending
     * state exists to prevent.
     */
    public function identifies(): bool
    {
        return IdentityLinkState::identifies($this->state);
    }

    public function needsAdministrator(): bool
    {
        return $this->state === IdentityLinkState::CONFLICT || $this->state === IdentityLinkState::PENDING;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'organisation_id' => $this->organisationId,
            'connection_id' => $this->connectionId,
            'issuer' => $this->issuer,
            'subject' => $this->subject,
            'user_id' => $this->userId,
            'external_id' => $this->externalId,
            'match_basis' => $this->matchBasis,
            'match_ref' => $this->matchRef,
            'state' => $this->state,
            'first_seen_at' => $this->firstSeenAt,
            'last_asserted_at' => $this->lastAssertedAt,
        ];
    }
}
