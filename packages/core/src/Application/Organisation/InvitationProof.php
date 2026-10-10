<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\Context\InvitationMatchContext;
use Shondalai\VidyaLMS\Core\Registry\InvitationMatch\InvitationMatchRegistry;

/** Fresh inbox possession bound to one authenticated account and invitation revision. */
final class InvitationProof
{
    private function __construct(
        public readonly int $organisationId,
        public readonly int $invitationId,
        public readonly int $userId,
        public readonly string $roleKey,
        public readonly int $organisationRevision,
        /**
         * The reservation this invitation promised, when it promised one.
         *
         * Carried on the proof rather than re-read by the acceptance, because
         * the proof is the moment the invitation row was last verified as
         * pending and a second read would be the same question asked twice
         * with a window in between.
         */
        public readonly ?int $seatAssignmentId,
    ) {
    }

    /**
     * The second acceptance gate.
     *
     * The possession checks below are the ceremony's and stay here: the proof
     * hash, the claim window, the invitation revision and the organisation's
     * own state are all facts about whether this code is still spendable, and
     * a registered policy may not turn any of them off. What moved to the
     * registry is the one question that was ever a policy: whether THIS
     * account's address is the invited one. An unresolved policy key refuses,
     * which is the published fail-closed direction and is indistinguishable
     * from an unknown claim.
     */
    public static function verify(
        DatabasePort $database,
        UsersPort $users,
        Principal $actor,
        int $claimId,
        string $code,
        string $now,
        InvitationMatchRegistry $matches
    ): self {
        $claim = $database->selectOne(Query::from('org_invitation_claims')->where('id', '=', $claimId)
            ->where('user_id', '=', $actor->userId));
        $user = $users->findById($actor->userId);
        if (
            $actor->isGuest() || $claim === null || $user === null || $claim['state'] !== 'pending'
            || (string) $claim['expires_at'] <= $now || !\is_string($claim['proof_hash'])
            || !hash_equals($claim['proof_hash'], hash('sha256', $code))
            || !hash_equals((string) $claim['email_hash'], hash('sha256', strtolower(trim($user->email))))
        ) {
            throw self::missing();
        }
        $invitation = $database->selectOne(Query::from('invitations')->where('id', '=', (int) $claim['invitation_id'])
            ->where('organisation_id', '=', (int) $claim['organisation_id']));
        $org = $database->selectOne(Query::from('organisations')->where('id', '=', (int) $claim['organisation_id']));
        if (
            $invitation === null || $org === null || $org['state'] !== 'active'
            || $invitation['state'] !== 'pending' || (string) $invitation['expires_at'] <= $now
            || (int) $invitation['revision'] !== (int) $claim['invitation_revision']
        ) {
            throw self::missing();
        }

        if (
            !$matches->matches(InvitationMatchRegistry::keyFor($org), InvitationMatchContext::forProof(
                (int) $claim['organisation_id'],
                (int) $invitation['id'],
                (int) $invitation['revision'],
                (string) $invitation['email'],
                $user->email,
                $actor->userId,
                $claimId,
            ))
        ) {
            throw self::missing();
        }

        return new self(
            (int) $claim['organisation_id'],
            (int) $invitation['id'],
            $actor->userId,
            (string) $invitation['role_key'],
            (int) $org['revision'],
            $invitation['seat_assignment_id'] === null ? null : (int) $invitation['seat_assignment_id']
        );
    }

    public static function missing(): EntityNotFoundException
    {
        return new EntityNotFoundException('This invitation cannot be used.');
    }
}
