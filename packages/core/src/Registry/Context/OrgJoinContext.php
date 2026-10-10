<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What a join rule decides against.
 *
 * It carries what the caller has already established rather than the means to
 * establish it: the organisation, the person, the role the join would grant,
 * and which of the recognised proofs was actually presented. A rule provider
 * therefore never issues a query, which is what keeps a third-party rule from
 * reading an organisation-scoped table with no predicate.
 *
 * PROOF_INVITATION is a fresh mailbox proof: the person answered a code sent
 * to the invited address inside its window. PROOF_NONE is the honest absence,
 * and the free rule refuses it.
 */
final class OrgJoinContext
{
    public const PROOF_NONE = 'none';
    public const PROOF_INVITATION = 'invitation';
    public const PROOF_VERIFIED_DOMAIN = 'verified_domain';
    public const PROOF_DIRECTORY_ASSERTION = 'directory_assertion';

    private function __construct(
        public readonly int $organisationId,
        public readonly int $userId,
        public readonly string $roleKey,
        public readonly string $proof,
    ) {
    }

    /** A join backed by a verified invitation proof, which is the free path. */
    public static function fromInvitationProof(int $organisationId, int $userId, string $roleKey): self
    {
        return new self($organisationId, $userId, $roleKey, self::PROOF_INVITATION);
    }

    public static function withProof(int $organisationId, int $userId, string $roleKey, string $proof): self
    {
        return new self($organisationId, $userId, $roleKey, $proof);
    }
}
