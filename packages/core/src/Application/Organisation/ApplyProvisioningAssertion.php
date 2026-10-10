<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IdentityLinkState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingDecision;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingRuleEvaluator;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MatchBasis;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MembershipRemovalGuard;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\OrgConnection;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IdentityKey;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipEvent;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentity;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\NamesGoverningSource;
use Shondalai\VidyaLMS\Core\Registry\ProvisioningSource\ProvisioningSourceRegistry;

/**
 * The one place an external assertion changes a membership.
 *
 * It is the real writer of org_memberships.directory_managed, which until now
 * was a column read in four places and written by nothing: the console refused
 * edits on it, erasure refused erasure on it, and the screen drew a third row
 * shape for it, all against a flag hard-coded to zero. A guard nothing can
 * trip is a guard nobody has tested.
 *
 * Four rules, in the order they run.
 *
 * **The connection must be verified.** An unverified issuer claim drives no
 * rule, creates no account and writes nothing. That is what makes an
 * unverified row inert rather than dangerous, and it is checked here before
 * anything is read, not after the rules have been evaluated.
 *
 * **Identity is the pair.** The link is found by (issuer, subject), byte
 * exact, and never by an address. A subject nobody has seen before with an
 * account already in hand is linked and recorded with the basis that found it;
 * a subject nobody has seen before with no account is refused unless the
 * connection may create accounts, and refusing is the honest answer rather
 * than inventing a person.
 *
 * **Absence never removes.** A membership is closed only when the removal
 * guard permits it, which needs a complete source AND an authoritative
 * connection, and even then only for the grants the decision no longer makes.
 * The decision records why a removal did not happen, so an operator whose
 * directory change did not take effect has something to read.
 *
 * **Provisioning sets provenance and never authority.** The source names where
 * a membership came from; the role comes from an administrator-authored rule
 * evaluated inside the connection's own subtree. No path here can raise
 * anybody above what a rule was allowed to name.
 */
final class ApplyProvisioningAssertion
{
    public function __construct(
        private readonly OrgIdentityStore $identity,
        private readonly OrganisationStore $organisations,
        private readonly ManageOrganisationConnections $connections,
        private readonly ProvisioningSourceRegistry $sources,
        private readonly MappingRuleEvaluator $evaluator = new MappingRuleEvaluator(),
    ) {
    }

    /**
     * Applies one assertion under one connection.
     *
     * The scope is the run's own, minted from the sweep authority or from the
     * caller that started the run, and the organisation it names is the only
     * one anything here may touch. Nothing reads a tenant out of the assertion:
     * a payload field that could choose its own tenant is the hole every
     * directory integration in this category has had at least once.
     */
    public function execute(
        OrgScope $scope,
        OrgConnection $connection,
        AssertedIdentity $identity,
        string $sourceKey,
        ?int $existingUserId = null,
        string $basis = MatchBasis::HOST_ACCOUNT,
    ): ProvisioningOutcome {
        if ($connection->organisationId !== $scope->organisationId) {
            return ProvisioningOutcome::refused('connection_outside_scope');
        }

        if ($identity->issuer !== $connection->issuer) {
            // The connection's verification, its rules and its authoritative
            // flag are statements about one issuer. Applying an assertion from
            // another issuer under them lends all three to a party nobody
            // verified: a rule written for the organisation's own directory
            // fires on a group name an attacker chose, and the link row lands
            // carrying the foreign issuer and this connection's id. Checked
            // before the rules are evaluated, and before anything is read.
            return ProvisioningOutcome::refused('issuer_mismatch');
        }

        if (!$connection->drivesProvisioning()) {
            return ProvisioningOutcome::refused('issuer_not_verified');
        }

        if (!$this->sources->has($sourceKey)) {
            // Fail closed on the identity path. A write attributed to a
            // provenance nothing registers is a write nobody can explain
            // afterwards, and the column it would land in is the one the
            // console reads to decide whether a row may be edited.
            return ProvisioningOutcome::refused('unregistered_source');
        }

        $decision = $this->evaluator->evaluate(
            $connection->rules,
            $identity,
            $this->connections->boundFor($scope, $connection),
            $connection->authoritative,
        );

        $link = $this->identity->linkFor($identity->issuer, $identity->subject);
        $userId = $link === null ? $existingUserId : $link->userId;

        if ($link === null) {
            // Rule two of the published match order, which had no
            // implementation: the pair did not match and the connection's own
            // immutable object id did, so this is the same person under a
            // subject the directory reissued.
            $byExternalId = $this->matchByExternalId($connection, $identity);

            if ($byExternalId !== null && $userId !== null && $userId > 0 && $byExternalId !== $userId) {
                // Two candidates, which is the case the design says raises a
                // row an administrator resolves. Nothing is merged, nothing is
                // granted, and the conflict is a row rather than a silent
                // preference for one of the two.
                $this->recordLink(
                    $scope,
                    $connection,
                    $identity,
                    $byExternalId,
                    MatchBasis::PENDING_CONFLICT,
                    IdentityLinkState::CONFLICT,
                );

                return ProvisioningOutcome::refused('identity_conflict', $decision);
            }

            if ($byExternalId !== null) {
                $userId = $byExternalId;
                $basis = MatchBasis::EXTERNAL_ID;
            }

            if ($userId === null || $userId < 1) {
                if (!$connection->mayCreateAccounts()) {
                    return ProvisioningOutcome::refused('no_account_and_creation_disabled', $decision);
                }

                // Creation is the host's job and this core never makes a CMS
                // account. A connection that may create accounts still needs
                // the host to have made one and handed the id over, so this
                // is an honest refusal rather than a silent skip.
                return ProvisioningOutcome::refused('account_not_supplied', $decision);
            }

            // The basis the caller actually used, not the one the pair would
            // have carried had it matched. Nothing had been seen before at
            // this point, so recording matched_subject here said the pair
            // matched byte for byte about a pair nobody had ever asserted.
            $conflict = $this->recordLink($scope, $connection, $identity, $userId, $basis);

            if ($conflict !== null) {
                return $conflict;
            }
        } else {
            if (!$link->identifies()) {
                return ProvisioningOutcome::refused('link_' . $link->state, $decision);
            }

            if ($link->connectionId !== $connection->id || ($existingUserId !== null && $existingUserId !== $link->userId)) {
                return ProvisioningOutcome::refused('identity_conflict', $decision);
            }

            $this->identity->updateLink($scope, $link->id, [
                'last_asserted_at' => $this->organisations->now(),
                'connection_id' => $connection->id,
            ]);
        }

        if ($userId === null || $userId < 1) {
            return ProvisioningOutcome::refused('account_not_supplied', $decision);
        }

        return $this->applyGrants($scope, $connection, $identity, $decision, $sourceKey, $userId);
    }

    /**
     * Whether this assertion is allowed to close anything, and why not.
     *
     * Published as its own method so a caller can ask before it acts and can
     * show the reason. The guard is the single one both halves of this area
     * use: the roster half asks the same object about a row's own status word.
     *
     * @return array{bool, string}
     */
    public function mayRemove(OrgConnection $connection, AssertedIdentity $identity): array
    {
        return MembershipRemovalGuard::rule()->permitsFromAssertion($identity, $connection->authoritative);
    }

    private function applyGrants(
        OrgScope $scope,
        OrgConnection $connection,
        AssertedIdentity $identity,
        MappingDecision $decision,
        string $sourceKey,
        int $userId,
    ): ProvisioningOutcome {
        $organisation = $this->organisations->organisation($scope);
        $revision = (int) ($organisation['revision'] ?? 1);
        $governed = $this->governs($sourceKey);
        $decisionRef = $this->decisionRef($connection, $decision);
        $memberId = $this->organisations->activeMemberId($scope, $userId);
        $granted = 0;

        if ($memberId !== null) {
            $existing = $this->organisations->member($scope, $memberId);
            // Signing in is not permission to take over a manual membership,
            // or one another connection owns. JIT only provisions new members.
            if (($existing['provisioned_by'] ?? '') !== $sourceKey || !$governed
                || ($existing['source_ref'] ?? '') !== self::membershipReference($connection->id, $identity->subject)) {
                return ProvisioningOutcome::applied($decision, 0, false, $decisionRef, $memberId);
            }
            $roles = $existing['role_keys'] ?? [];
            if ($this->connections->holdsOwnership($roles)) {
                return ProvisioningOutcome::applied($decision, 0, false, $decisionRef, $memberId);
            }
        }

        foreach ($decision->grants as $grant) {
            if ($memberId === null) {
                $memberId = $this->organisations->addMember(
                    $scope,
                    $userId,
                    $grant->roleKey,
                    $sourceKey,
                    self::membershipReference($connection->id, $identity->subject),
                    $grant->orgUnitId,
                );

                $this->organisations->event(
                    $scope,
                    $revision,
                    MembershipEvent::JOINED,
                    $userId,
                    ['connection_id' => $connection->id, 'role_key' => $grant->roleKey],
                    MembershipEventContext::directory(
                        $identity->subject,
                        $decisionRef,
                        $grant->orgUnitId,
                        $grant->roleKey,
                    ),
                );
            } else {
                $this->organisations->setRole($scope, $memberId, $grant->roleKey, $grant->orgUnitId);
                $this->organisations->event(
                    $scope,
                    $revision,
                    MembershipEvent::ROLE_GRANTED,
                    $userId,
                    ['connection_id' => $connection->id, 'role_key' => $grant->roleKey],
                    MembershipEventContext::directory(
                        $identity->subject,
                        $decisionRef,
                        $grant->orgUnitId,
                        $grant->roleKey,
                    ),
                );
            }

            $granted++;
        }

        if ($memberId !== null && $governed) {
            // The real write. A row a source governs is read only in the
            // console, and the console has refused edits on this flag since the
            // spine shipped; this is the first thing that can set it.
            $this->organisations->setDirectory($scope, $memberId, ['directory_managed' => 1]);
        }

        return ProvisioningOutcome::applied($decision, $granted, $governed, $decisionRef, $memberId);
    }

    public static function membershipReference(int $connectionId, string $subject): string
    {
        return 'connection:' . $connectionId . ':' . hash('sha256', $subject);
    }

    /**
     * Writes the link, or answers the conflict the unique index raised.
     *
     * The index on identity_key is the last word on whether a pair is already
     * linked, and it has to be: two assertions arriving at once both read no
     * link and both insert. An unhandled UniqueConstraintViolation out of an
     * infrastructure adapter at a login is a white screen, which is the one
     * thing this whole class refuses to produce, so it is answered as a
     * refusal in the published vocabulary instead.
     */
    private function recordLink(
        OrgScope $scope,
        OrgConnection $connection,
        AssertedIdentity $identity,
        int $userId,
        string $basis,
        ?string $state = null,
    ): ?ProvisioningOutcome {
        $now = $this->organisations->now();

        try {
            $this->insertLink($scope, $connection, $identity, $userId, $basis, $now, $state);
        } catch (UniqueConstraintViolation) {
            return ProvisioningOutcome::refused('identity_already_linked');
        }

        return null;
    }

    /**
     * The account this connection's own external id already names, or null.
     *
     * Asked only when the pair did not match, and only inside this connection,
     * because an external id is the issuing directory's word and means nothing
     * across two of them.
     */
    private function matchByExternalId(OrgConnection $connection, AssertedIdentity $identity): ?int
    {
        $externalId = $identity->attributes['external_id'] ?? null;

        if (!\is_string($externalId) || trim($externalId) === '') {
            return null;
        }

        $link = $this->identity->linkByExternalId($connection->id, $externalId);

        return $link === null || !$link->identifies() ? null : $link->userId;
    }

    private function insertLink(
        OrgScope $scope,
        OrgConnection $connection,
        AssertedIdentity $identity,
        int $userId,
        string $basis,
        string $now,
        ?string $state = null,
    ): void {
        $this->identity->insertLink([
            'organisation_id' => $scope->organisationId,
            'connection_id' => $connection->id,
            'issuer' => $identity->issuer,
            'subject' => $identity->subject,
            'identity_key' => IdentityKey::of($identity->issuer, $identity->subject),
            'user_id' => $userId,
            'external_id' => $identity->attributes['external_id'] ?? null,
            'match_basis' => MatchBasis::require($basis),
            'match_ref' => $identity->assertionRef,
            // An assertion from a connection nobody marked authoritative
            // produces a pending link and never an active one, which is the
            // direct counter to a plugin linking an assertion to an existing
            // account because the address already existed.
            'state' => $state ?? ($connection->authoritative
                ? IdentityLinkState::ACTIVE
                : IdentityLinkState::PENDING),
            'first_seen_at' => $now,
            'last_asserted_at' => $now,
        ]);
    }

    /** Whether the registered source governs the rows it writes. */
    private function governs(string $sourceKey): bool
    {
        if (!$this->sources->has($sourceKey)) {
            return false;
        }
        $source = $this->sources->get($sourceKey);
        return $source instanceof NamesGoverningSource && $source->governedFields() !== [];
    }

    /**
     * Where the explainer lives, as a reference the event stream points at.
     *
     * "connection:{id}:{digest of the decision}" rather than the decision
     * itself, because an append-only stream carrying a whole decision on every
     * row is a stream nobody can page, and because the decision belongs beside
     * the connection that produced it.
     */
    private function decisionRef(OrgConnection $connection, MappingDecision $decision): string
    {
        $encoded = json_encode($decision->toStored(), JSON_THROW_ON_ERROR);

        return 'connection:' . $connection->id . ':' . hash('sha256', $encoded);
    }
}
