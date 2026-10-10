<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\OrganisationInvitationDelivery;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\Context\InvitationMatchContext;
use Shondalai\VidyaLMS\Core\Registry\Context\OrgJoinContext;
use Shondalai\VidyaLMS\Core\Registry\InvitationMatch\InvitationMatchRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgJoinRule\OrgJoinRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;

/**
 * Two-step strict-email acceptance works on hosts without verified-email metadata.
 * A forwarded link alone is insufficient: a fresh proof goes to the original
 * invited inbox and is bound to the logged-in account and its current address.
 */
final class ManageOrganisationInvitations
{
    /**
     * How long an invitation stays usable, in days.
     *
     * Seven, following GitHub, and it is a settings row rather than a
     * hardcoded interval because the design says the value is the
     * organisation's to choose. {@see expiryDaysFor()} is the seam that
     * per-organisation choice arrives through and it is a clamp with one
     * caller and no stored per-organisation value today, on exactly the terms
     * AccessLogRetentionTask::retentionDaysFor() states: there is no
     * organisations column to read, so every organisation on every site gets
     * the site value. The column, its write route and its audited row land
     * with the wave that gives organisations their own policy storage, and
     * when they do this method reads it and nothing else changes.
     */
    public const EXPIRY_SETTING = 'organisation.invitation_expiry_days';
    public const DEFAULT_EXPIRY_DAYS = 7;

    /** The longest an invitation may be made to live, so a nonsense setting cannot mint an immortal token. */
    public const MAX_EXPIRY_DAYS = 365;

    public function __construct(
        private readonly OrgScopeResolver $access,
        private readonly OrganisationStore $store,
        private readonly ManageOrganisationMembers $members,
        private readonly OrgRoleRegistry $roles,
        private readonly UsersPort $users,
        private readonly ClockPort $clock,
        private readonly RandomPort $random,
        private readonly OrganisationInvitationDelivery $delivery,
        private readonly InvitationAttemptLimit $limits,
        /**
         * The join rules, asked before anybody becomes a member.
         *
         * Required rather than defaulted, because a defaulted empty registry
         * would fail closed and stop every acceptance on a caller that simply
         * had not been updated, which is a silent outage wearing a correct
         * direction. It is not a new gate: it is the gate this path already
         * was, moved to where a package can replace it. Removing the provider
         * from a seeded registry does stop every acceptance, which is the
         * published direction, rather than falling back to admitting anybody.
         */
        private readonly OrgJoinRuleRegistry $joinRules,
        /**
         * Which account may use an invitation addressed to an address.
         *
         * Required on the same terms the join rules are: it replaces a
         * comparison this class already made, so a defaulted empty registry
         * would fail closed and stop every acceptance on a caller that had
         * simply not been updated. Removing the provider from a seeded
         * registry does stop every acceptance, which is the published
         * direction.
         */
        private readonly InvitationMatchRegistry $matches,
        /** Where the per-organisation expiry is read from. */
        private readonly Settings $settings,
        /**
         * The reserve entry point, so an invitation into a pool takes the seat
         * it promises at the moment it promises it.
         *
         * Nullable because an organisation with no seat pool never reaches it
         * and a host that has not built the seat collaborators still gets a
         * working invitation ceremony; a request naming a pool with no
         * reservations wired is refused rather than silently promising a seat
         * nobody counted.
         */
        private readonly ?SeatReservations $reservations = null,
        /**
         * Where this organisation's own join rule row is read from.
         *
         * Optional and trailing, so every caller written before the row
         * existed still constructs and still behaves exactly as it did: a null
         * store means the registry's own default key, which is the strict free
         * rule this path has always asked for.
         *
         * It exists because the registry was registrable and not selectable.
         * Three call sites named DEFAULT_KEY, so a package could register a
         * second rule and no site could ever choose it, which is a seam that
         * looks open and is closed. The row is now what chooses, and a key no
         * installed provider answers is refused when the row is saved rather
         * than discovered here.
         */
        private readonly ?OrgIdentityStore $joinRuleRows = null,
    ) {
    }

    /**
     * Which join rule this organisation is under right now.
     *
     * The registry's own default when there is no row and when no store was
     * wired, because an organisation nobody has configured is under the strict
     * free rule and that is the answer that has always been given here. A key
     * the registry cannot resolve is returned as it is and refused by
     * admits(), which is the published fail-closed direction: a gate nobody
     * can answer answers no.
     */
    private function joinRuleKeyFor(OrgScope $scope): string
    {
        if ($this->joinRuleRows === null) {
            return OrgJoinRuleRegistry::DEFAULT_KEY;
        }

        $row = $this->joinRuleRows->activeJoinRule($scope, $this->clock->now());

        return $row === null ? OrgJoinRuleRegistry::DEFAULT_KEY : $row->ruleKey;
    }

    /**
     * How long an invitation issued into this organisation stays usable.
     *
     * The clamp is the rule a per-organisation value may never break: never
     * below a day, because an invitation that expires before the mail is read
     * is a support ticket, and never above a year, because a token is a
     * bearer credential and a nonsense settings row must not mint an
     * immortal one.
     */
    public function expiryDaysFor(int $organisationId, ?int $requestedDays = null): int
    {
        $days = $requestedDays ?? $this->settings->getIntish(self::EXPIRY_SETTING, self::DEFAULT_EXPIRY_DAYS);

        if ($days < 1) {
            return self::DEFAULT_EXPIRY_DAYS;
        }

        return min($days, self::MAX_EXPIRY_DAYS);
    }

    /** @return array<string, mixed> */
    public function list(Principal $actor, int $orgId, int $page = 1, int $perPage = 25, string $state = ''): array
    {
        $scope = $this->access->require($actor, $orgId, 'people');
        $this->requireOrganisationScope($scope);
        OrganisationInput::page($page, $perPage);
        if (!\in_array($state, ['', 'pending', 'accepted', 'revoked', 'expired', 'bounced'], true)) {
            throw ValidationException::forField('state', 'Unsupported invitation state.');
        }
        $query = Query::from('invitations')->where('organisation_id', '=', $scope->organisationId);
        if ($state === 'expired') {
            $query->where('state', '=', 'pending')->where('expires_at', '<=', $this->store->now());
        } elseif ($state !== '') {
            $query->where('state', '=', $state);
            if ($state === 'pending') {
                $query->where('expires_at', '>', $this->store->now());
            }
        }
        $total = $this->store->database->count(clone $query);
        $rows = $this->store->database->selectAll($query->orderBy('id')->limit($perPage)->offset(($page - 1) * $perPage));

        return ['items' => array_map($this->present(...), $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function issue(Principal $actor, int $orgId, array $input, bool $rateLimitAlreadyConsumed = false): array
    {
        $scope = $this->access->require($actor, $orgId, 'people');
        $this->requireOrganisationScope($scope);
        OrganisationInput::only($input, ['expected_revision', 'email', 'role_key', 'pool_id']);
        $expected = OrganisationInput::integer($input, 'expected_revision');
        $email = strtolower(OrganisationInput::text($input, 'email', 190));
        $role = OrganisationInput::text($input, 'role_key', 60);
        // Absent means "unchanged" on a reissue and "membership only" on a
        // first issue. It is not defaulted to a pool, because an invitation
        // that quietly spent a seat nobody asked it to spend is the same
        // arithmetic defect from the other direction.
        $pool = \array_key_exists('pool_id', $input) ? OrganisationInput::integer($input, 'pool_id') : null;
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw ValidationException::forField('email', 'Expected a valid email address.');
        }
        if ($pool !== null && $this->reservations === null) {
            throw ValidationException::forField('pool_id', 'Seat reservation is not available on this site.');
        }

        if (!$rateLimitAlreadyConsumed) {
            $this->limitIssue($actor, $orgId, $email);
        }

        return $this->store->database->transactional(function () use ($actor, $orgId, $expected, $email, $role, $pool): array {
            $scope = $this->access->require($actor, $orgId, 'people');
            $this->requireOrganisationScope($scope);
            $this->members->assertRole($actor, $scope, $role);
            // Every accepted request consumes the same revision and exposes
            // the same receipt, including requests for existing members.
            $next = $this->store->claim($scope, $expected);
            $account = $this->users->findByEmail($email);
            $activeMemberId = $account === null ? null : $this->store->activeMemberId($scope, $account->id);
            if ($activeMemberId !== null) {
                return ['organisation_revision' => $next, 'outcome' => 'queued'];
            }
            $pendingKey = self::pendingKey($orgId, $email);
            $existing = $this->store->database->selectOne(Query::from('invitations')
                ->where('organisation_id', '=', $orgId)->where('pending_key', '=', $pendingKey))
                ?? $this->reusable($orgId, $email);
            $revision = $existing === null ? 1 : (int) $existing['revision'] + 1;
            $token = $this->random->verificationCode(64);
            $window = new \DateInterval('P' . $this->expiryDaysFor($orgId) . 'D');
            $expires = $this->clock->now()->add($window)->format('Y-m-d H:i:s');
            $row = [
                'organisation_id' => $orgId, 'email' => $email, 'role_key' => $role, 'state' => 'pending',
                'pending_key' => $pendingKey, 'token_hash' => hash('sha256', $token), 'revision' => $revision,
                'expires_at' => $expires, 'issued_by' => $actor->userId, 'accepted_by' => null, 'accepted_at' => null,
                'updated_at' => $this->store->now(),
            ];
            if ($existing === null) {
                $id = $this->store->database->insert('invitations', $row + ['created_at' => $this->store->now()]);
            } else {
                $id = (int) $existing['id'];
                $this->store->database->update('invitations', $row, ['organisation_id' => $orgId, 'id' => $id]);
                $this->invalidateClaims($orgId, $id);
            }
            // Reserve-at-issue, before the mail is queued: the seat the
            // invitation promises is taken now, so the forty first invitation
            // into a forty seat pool is refused here rather than at somebody
            // else's acceptance a week later.
            $reservationId = $this->reserveFor(
                $scope,
                $next,
                $id,
                $pool,
                $existing === null || $existing['seat_assignment_id'] === null
                    ? null : (int) $existing['seat_assignment_id'],
                $account?->id,
                $expires
            );
            $row['seat_assignment_id'] = $reservationId;
            $this->store->database->update(
                'invitations',
                ['seat_assignment_id' => $reservationId, 'updated_at' => $this->store->now()],
                ['organisation_id' => $orgId, 'id' => $id]
            );
            $this->delivery->queue(
                'invitation',
                $orgId,
                $id,
                null,
                $email,
                $token,
                $expires,
                'org-invitation:' . $id . ':' . $revision
            );
            $this->store->event(
                $scope,
                $next,
                $existing === null ? 'invitation.issued' : 'invitation.reissued',
                null,
                ['invitation_id' => $id, 'role_key' => $role],
                MembershipEventContext::invitation((string) $id, $role),
            );

            return ['organisation_revision' => $next, 'outcome' => 'queued'];
        });
    }

    /** @return array<string, mixed> */
    public function revoke(Principal $actor, int $orgId, int $invitationId, int $expectedRevision): array
    {
        return $this->store->database->transactional(function () use ($actor, $orgId, $invitationId, $expectedRevision): array {
            $scope = $this->access->require($actor, $orgId, 'people');
            $this->requireOrganisationScope($scope);
            $row = $this->store->database->selectOne(Query::from('invitations')->where('organisation_id', '=', $orgId)
                ->where('id', '=', $invitationId)) ?? throw InvitationProof::missing();
            if ($row['state'] !== 'pending') {
                throw new ConflictException('Only a pending invitation can be revoked.');
            }
            $next = $this->store->claim($scope, $expectedRevision);
            $this->store->database->update(
                'invitations',
                ['state' => 'revoked', 'pending_key' => null,
                'token_hash' => null, 'updated_at' => $this->store->now()],
                ['organisation_id' => $orgId, 'id' => $invitationId]
            );
            $this->releaseReservation(
                $scope,
                $next,
                $invitationId,
                $row['seat_assignment_id'] === null ? null : (int) $row['seat_assignment_id'],
                SeatReservations::REASON_RECLAIMED
            );
            $this->invalidateClaims($orgId, $invitationId);
            $this->store->event(
                $scope,
                $next,
                'invitation.revoked',
                null,
                ['invitation_id' => $invitationId],
                MembershipEventContext::invitation((string) $invitationId),
            );

            return ['organisation_revision' => $next];
        });
    }

    /** Call before opening the request transaction, including idempotent replays. */
    public function limitIssue(Principal $actor, int $orgId, string $email): void
    {
        $scope = $this->access->require($actor, $orgId, 'people');
        $this->requireOrganisationScope($scope);
        $this->limits->consume('issue:actor:' . $actor->userId, 100, 3600);
        $this->limits->consume('issue:organisation:' . $orgId, 250, 3600);
        $this->limits->consume('issue:recipient:' . hash('sha256', strtolower(trim($email))), 5, 86400);
    }

    /** Must run outside any caller transaction: failed guesses must retain their counters. */
    public function limitAttempt(Principal $actor, string $kind, string $subject, ?string $clientAddress): void
    {
        if ($actor->isGuest()) {
            throw new \Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException('Authentication is required.');
        }
        $this->limits->consume('actor:' . $actor->userId, 20);
        // Numeric claim ids are not secrets. A different account cannot
        // exhaust somebody else's proof bucket merely by guessing an id.
        $subjectKey = $kind === 'accept' ? $actor->userId . ':' . $subject : $subject;
        $this->limits->consume($kind . ':' . hash('sha256', $subjectKey), 5);
        if ($clientAddress !== null && $clientAddress !== '') {
            $this->limits->consume('address:' . hash('sha256', $clientAddress), 40);
        }
    }

    /** Caller invokes limitAttempt before entering any idempotency transaction.
     * @return array<string, mixed>
     */
    public function claim(Principal $actor, string $token): array
    {
        return $this->store->database->transactional(function () use ($actor, $token): array {
            $row = $this->pendingForToken($actor, $token);
            $orgId = (int) $row['organisation_id'];
            $id = (int) $row['id'];
            $code = $this->random->verificationCode(12);
            $expires = $this->clock->now()->add(new \DateInterval('PT10M'))->format('Y-m-d H:i:s');
            $this->store->database->update('org_invitation_claims', ['state' => 'revoked', 'proof_hash' => null], [
                'organisation_id' => $orgId, 'invitation_id' => $id, 'user_id' => $actor->userId, 'state' => 'pending',
            ]);
            $claimId = $this->store->database->insert('org_invitation_claims', [
                'organisation_id' => $orgId, 'invitation_id' => $id, 'invitation_revision' => (int) $row['revision'],
                'user_id' => $actor->userId, 'email_hash' => hash('sha256', (string) $row['email']),
                'proof_hash' => hash('sha256', $code), 'state' => 'pending', 'expires_at' => $expires,
                'created_at' => $this->store->now(), 'updated_at' => $this->store->now(),
            ]);
            $this->delivery->queue(
                'proof',
                $orgId,
                $id,
                $claimId,
                (string) $row['email'],
                $code,
                $expires,
                'org-invitation-proof:' . $claimId
            );

            return ['claim_id' => $claimId, 'expires_at' => $expires, 'delivery' => 'queued'];
        });
    }

    /** Caller invokes limitAttempt before this transaction. No secret enters the result.
     * @return array<string, mixed>
     */
    public function accept(Principal $actor, int $claimId, string $code): array
    {
        return $this->store->database->transactional(function () use ($actor, $claimId, $code): array {
            $proof = InvitationProof::verify(
                $this->store->database,
                $this->users,
                $actor,
                $claimId,
                $code,
                $this->store->now(),
                $this->matches
            );
            if (!$this->roles->has($proof->roleKey)) {
                throw InvitationProof::missing();
            }
            // The join rule decides, and the free one admits only a fresh
            // mailbox proof. It is asked after the proof has been verified and
            // before the membership is written, so a rule can refuse a join
            // the ceremony would otherwise have completed. A refusal is the
            // same indistinguishable answer as an unknown token: the rule is
            // the organisation's business and not the caller's to enumerate.
            $join = OrgJoinContext::fromInvitationProof($proof->organisationId, $proof->userId, $proof->roleKey);
            // The scope is minted before the rule is asked rather than after,
            // because the rule row is an organisation-owned read and every one
            // of those is narrowed by a scope an authorisation produced. The
            // proof is that authorisation, so nothing is read earlier than it
            // could have been.
            $scope = OrgScope::fromInvitationProof($proof);
            if (!$this->joinRules->admits($this->joinRuleKeyFor($scope), $join)) {
                throw InvitationProof::missing();
            }
            $next = $this->store->claim($scope, $proof->organisationRevision);
            $changed = $this->store->database->update('invitations', [
                'state' => 'accepted', 'accepted_by' => $actor->userId, 'accepted_at' => $this->store->now(),
                'pending_key' => null, 'token_hash' => null, 'updated_at' => $this->store->now(),
            ], ['organisation_id' => $scope->organisationId, 'id' => $proof->invitationId, 'state' => 'pending']);
            if ($changed !== 1) {
                throw InvitationProof::missing();
            }
            $memberId = $this->store->activeMemberId($scope, $actor->userId)
                ?? $this->store->addMember(
                    $scope,
                    $actor->userId,
                    $proof->roleKey,
                    'invitation',
                    'org-invitation:' . $proof->invitationId
                );
            $this->invalidateClaims($scope->organisationId, $proof->invitationId);
            $this->store->database->update(
                'org_invitation_claims',
                ['state' => 'consumed', 'proof_hash' => null],
                ['organisation_id' => $scope->organisationId, 'id' => $claimId, 'user_id' => $actor->userId]
            );
            // The reservation the invitation was holding becomes the seat the
            // member now has. It is a conversion rather than a fresh
            // assignment, so the ledger charges the pool once: seat_reserved
            // took the seat and seat_assigned records that a person now holds
            // the one already taken.
            //
            // A link the sweeps have already ended is treated as no
            // reservation rather than as an error: the acceptance completes
            // with a membership and no seat, which is what an invitation whose
            // seat went back to the pool actually promises now. It used to
            // throw from inside the acceptance transaction, so the invitee
            // passed both gates, received a proof code, and then could never
            // finish; the invitation stayed pending for ever.
            $assignmentId = $proof->seatAssignmentId;
            $reservation = $assignmentId === null || $this->reservations === null
                ? null : $this->reservations->heldReservation($scope, $assignmentId);
            if ($assignmentId !== null && $reservation !== null && $this->reservations !== null) {
                $this->reservations->convert($scope, $next, $assignmentId, $memberId, $actor->userId);
            } elseif ($assignmentId !== null) {
                // The stale link is cleared with the row it named, so nothing
                // downstream reads an invitation as holding a seat it lost.
                $this->store->database->update(
                    'invitations',
                    ['seat_assignment_id' => null],
                    ['organisation_id' => $scope->organisationId, 'id' => $proof->invitationId]
                );
                $assignmentId = null;
            }
            $this->store->event(
                $scope,
                $next,
                'invitation.accepted',
                $actor->userId,
                ['invitation_id' => $proof->invitationId, 'membership_id' => $memberId],
                MembershipEventContext::invitation((string) $proof->invitationId),
            );

            return ['organisation_id' => $scope->organisationId, 'membership_id' => $memberId,
                'organisation_revision' => $next, 'state' => 'active',
                'seat_assignment_id' => $assignmentId];
        });
    }

    /** @return array<string, mixed> */
    private function pendingForToken(Principal $actor, string $token): array
    {
        $user = $this->users->findById($actor->userId);
        $row = $this->store->database->selectOne(Query::from('invitations')->where('token_hash', '=', hash('sha256', $token)));
        if (
            $actor->isGuest() || $user === null || $row === null || $row['state'] !== 'pending'
            || (string) $row['expires_at'] <= $this->store->now() || !\is_string($row['token_hash'])
            || !hash_equals($row['token_hash'], hash('sha256', $token))
        ) {
            throw InvitationProof::missing();
        }
        // The organisation is read before the match, because the organisation
        // is what chooses the policy. All three call sites used to name the
        // registry's compile-time default, so a registered provider was never
        // asked anything; the column that chooses defaults to the same key, so
        // nothing about the free answer changes.
        $org = $this->store->findForAccess((int) $row['organisation_id']);
        if ($org === null || $org['state'] !== 'active') {
            throw InvitationProof::missing();
        }
        // The first of the two acceptance gates, and the one that used to be a
        // hardcoded comparison. A forwarded link still gets no further than
        // here under the free policy, and a package registering `relaxed`
        // changes this answer without changing anything else on the path.
        if (
            !$this->matches->matches(InvitationMatchRegistry::keyFor($org), InvitationMatchContext::forToken(
                (int) $row['organisation_id'],
                (int) $row['id'],
                (int) $row['revision'],
                (string) $row['email'],
                $user->email,
                $actor->userId,
            ))
        ) {
            throw InvitationProof::missing();
        }

        return $row;
    }

    /**
     * The idempotency key for one pending invitation, as a composite hash.
     *
     * **The organisation id cannot be parsed back out of this value and that
     * is deliberate.** The design describes the key as
     * `'{organisation_id}:{email_hash}'`, which is readable in both
     * directions; what shipped, and what stays, is one sha256 over the pair.
     * The property the unique index needs is that two different (organisation,
     * address) pairs never collide and one pair always produces the same
     * value, and a digest of the pair holds it in a fixed 64 characters where
     * the composite form does not: `email` is `string:190`, so a long address
     * plus an id and a separator overflows the `string:64` key column, and a
     * truncating configuration would then collapse two distinct addresses onto
     * one key and refuse the second invitation, which is the opposite of the
     * property this key exists to give.
     *
     * The cost is stated rather than discovered: nothing can list one
     * organisation's pending keys from the keys themselves, and every query
     * that needs the tenant reads `organisation_id`, which is the column the
     * whole tenancy rule filters on anyway.
     */
    public static function pendingKey(int $organisationId, string $email): string
    {
        return hash('sha256', $organisationId . ':' . strtolower(trim($email)));
    }

    /**
     * The finished invitation to this address that a reissue revives.
     *
     * The pending key is nulled the moment an invitation stops being pending,
     * which is what frees the unique index for a genuinely new promise. So a
     * lookup by pending key alone found nothing after an expiry and the
     * reissue inserted a second row: one address, one organisation, two
     * invitations, and the published machine draws `expired` to `pending` as a
     * reissue that reuses the same row precisely so a re-uploaded corrected
     * CSV cannot mail everybody twice.
     *
     * Only `expired` is revived, because that is the one edge the published
     * machine draws back into `pending` and the one whose row records nothing
     * anybody decided. `accepted` records that somebody joined, `revoked`
     * records that an administrator withdrew this promise, and `bounced`
     * records what a mail server said about this address on that day: reusing
     * any of the three would overwrite a fact with a fresh promise, so those
     * get a new row. The newest expired row is taken, so a history of several
     * is not re-scrambled.
     *
     * @return array<string, mixed>|null
     */
    private function reusable(int $orgId, string $email): ?array
    {
        return $this->store->database->selectOne(Query::from('invitations')
            ->where('organisation_id', '=', $orgId)
            ->where('email', '=', $email)
            ->where('state', '=', 'expired')
            ->orderBy('id', 'DESC'));
    }

    /**
     * The reservation this issue should end holding, or null.
     *
     * Three cases and no fourth. No pool named and no reservation: nothing
     * happens. No pool named and a reservation already held: it is kept,
     * because a reissue re-sends a promise rather than withdrawing it. A pool
     * named: the seat is taken there, and a reservation held in a different
     * pool is released first, so an administrator moving an outstanding
     * invitation between pools cannot leave the first pool one seat short
     * with nothing pointing at the row.
     */
    private function reserveFor(
        OrgScope $scope,
        int $revision,
        int $invitationId,
        ?int $poolId,
        ?int $heldAssignmentId,
        ?int $accountId,
        string $expiresAt,
    ): ?int {
        if ($this->reservations === null || ($poolId === null && $heldAssignmentId === null)) {
            return null;
        }

        // A link that is no longer a reservation is no reservation, not an
        // error. Two sweeps end a reservation without touching the invitation
        // that took it: the reservation sweep cancels it once its window
        // passes, and the pool term sweep forfeits it when the term ends.
        // Asking for the row as though it must still be reserved threw an
        // EntityNotFoundException from inside the issue transaction, so an
        // administrator repairing a swept invitation by reissuing it into the
        // same pool got a 404 and the whole issue rolled back.
        $held = $heldAssignmentId === null ? null : $this->reservations->heldReservation($scope, $heldAssignmentId);

        if ($poolId === null) {
            // No pool named means "unchanged", and unchanged is nothing at all
            // when the link is stale: returning the id of a cancelled row left
            // the console showing a promise the pool was not holding, and the
            // acceptance then failed on a reservation that no longer existed.
            return $held === null ? null : $heldAssignmentId;
        }

        if ($held !== null) {
            if ((int) $held['pool_id'] === $poolId) {
                return $heldAssignmentId;
            }

            $this->reservations->release($scope, $revision, $heldAssignmentId ?? 0, SeatReservations::REASON_RECLAIMED);
        }

        return $this->reservations->reserve(
            $scope,
            $revision,
            $poolId,
            'inv-' . $invitationId,
            $accountId,
            new \DateTimeImmutable($expiresAt),
        )['id'];
    }

    /**
     * Gives back the seat an invitation was holding, when it was holding one.
     *
     * Revocation and expiry both end here, with different reasons, because
     * the seat moves the same way and only the cause differs. A reservation
     * somebody has already claimed is a live assignment and is left alone:
     * releasing it would take a learner's access away as a side effect of
     * tidying an invitation row.
     */
    private function releaseReservation(OrgScope $scope, int $revision, ?int $invitationId, ?int $assignmentId, string $reason): void
    {
        if ($this->reservations === null || $assignmentId === null) {
            return;
        }

        try {
            $this->reservations->release($scope, $revision, $assignmentId, $reason);
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException) {
            // Already converted or already released. Both are terminal for the
            // reservation and neither is a failure of the revocation.
            return;
        }

        // The link goes with the seat. An invitation still naming a released
        // reservation is a promise the pool is not holding, and every reader
        // of the column has to guess whether it is live.
        if ($invitationId !== null) {
            $this->store->database->update(
                'invitations',
                ['seat_assignment_id' => null],
                ['organisation_id' => $scope->organisationId, 'id' => $invitationId]
            );
        }
    }

    private function invalidateClaims(int $orgId, int $invitationId): void
    {
        $this->store->database->update(
            'org_invitation_claims',
            ['state' => 'revoked', 'proof_hash' => null],
            ['organisation_id' => $orgId, 'invitation_id' => $invitationId, 'state' => 'pending']
        );
    }

    private function requireOrganisationScope(OrgScope $scope): void
    {
        if ($scope->unitId !== null) {
            throw new \Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException(
                'Organisation-wide invitations require an organisation-wide administrator.'
            );
        }
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        unset($row['token_hash'], $row['pending_key']);
        foreach (['id', 'organisation_id', 'revision', 'issued_by', 'accepted_by', 'seat_assignment_id'] as $key) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        if ($row['state'] === 'pending' && (string) $row['expires_at'] <= $this->store->now()) {
            $row['state'] = 'expired';
        }

        return $row;
    }
}
