<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * A non-empty, single-organisation scope minted only after the resolver's check.
 *
 * Tenancy is a type rather than a discipline, because isolation implemented as
 * a property of a set of screens leaks the first time one screen forgets.
 * There is no site-wide scope and no all(): a value object whose empty state
 * means every tenant is the shape a bug reaches by accident, so the
 * constructor refuses anything that is not one positive organisation and one
 * positive actor.
 *
 * **Multi-organisation scopes are refused rather than deferred, and the
 * refusal is structural.** There is no factory that takes a list, no
 * constructor that accepts one, and none is planned: a caller who genuinely
 * spans organisations is a paged site-scoped use case that takes no OrgScope
 * at all. The design's carried value is a sorted id list, so the two
 * accessors below answer in that shape and the digest is computed over it;
 * a later wave that admits a second organisation therefore widens the
 * resolver and the constructor without changing the digest formula, and the
 * v1 prefix is what invalidates every stored digest on the day it does.
 *
 * Construction goes through three factories, each taking a witness only a
 * verified path can mint: an authorisation, a fresh mailbox proof, or a
 * durable job's own recorded tenant. tooling/ci/gate.php fails the build on
 * "new OrgScope", on any OrgScope:: call and on an aliased import of this
 * class outside those three files plus this one, because PHP has no friend
 * classes and a reviewer cannot check an aspiration.
 */
final class OrgScope
{
    /** A person acting through a role their organisation granted them. */
    public const BASIS_ROLE_GRANT = 'role_grant';

    /** A site manager entering an organisation route, scoped like anyone else. */
    public const BASIS_SITE_CAPABILITY = 'site_capability';

    /** A person acting on a proof they presented, rather than on a standing role. */
    public const BASIS_MANUAL = 'manual';

    /** A directory or roster acting under a connection's own authority. */
    public const BASIS_PROVISIONING = 'provisioning';

    /** A scheduled or queued job acting on a tenant it recorded earlier. */
    public const BASIS_SYSTEM = 'system';

    /**
     * The field disclosure policy in force until a site chooses another.
     *
     * **Declared and not yet applied**, in the same words the empty registries
     * use. The FieldDisclosure registry has no consumer, so no field is
     * omitted by any policy and all three factories pass this default, which
     * makes it a digest input that cannot vary. The reason for hashing it in
     * anyway is real and is why it is here before it is used: a reader allowed
     * more columns and a reader allowed fewer must not share a cache key, and
     * adding the input later would mean every digest already stored was
     * computed without it. The factories resolve it from the registry on the
     * day a provider exists; the field is derived then rather than defaulted.
     */
    public const DISCLOSURE_ORG_MINIMAL = 'org_minimal';

    /** The version of the shape DISCLOSURE_ORG_MINIMAL describes. */
    public const DISCLOSURE_VERSION = 1;

    /**
     * The scope-shape version, bumped whenever the resolution algorithm
     * changes, so every stored digest is invalidated at once rather than an
     * old digest matching a differently-resolved new scope.
     */
    private const DIGEST_SHAPE = 'v1';

    /** @param list<string> $roles */
    private function __construct(
        public readonly int $organisationId,
        public readonly int $actorId,
        public readonly array $roles,
        public readonly bool $siteManager,
        public readonly ?int $unitId = null,
        /**
         * How this scope was reached: one of the five basis constants. It is a
         * digest input because a site manager and an organisation reporter
         * over one organisation legitimately see different rows, so an
         * artefact computed for one must never be served to the other.
         */
        public readonly string $basis = self::BASIS_ROLE_GRANT,
        public readonly string $disclosureKey = self::DISCLOSURE_ORG_MINIMAL,
        public readonly int $disclosureVersion = self::DISCLOSURE_VERSION,
        /**
         * The units this scope reaches, descendants folded in, sorted.
         *
         * Resolved by the resolver rather than derived here, because the
         * expansion is a closure read and this object holds no database
         * handle. Empty with a null unitId means the whole organisation.
         *
         * @var list<int>
         */
        private readonly array $resolvedUnitIds = [],
    ) {
        if ($organisationId < 1 || $actorId < 1) {
            throw new \InvalidArgumentException('An organisation scope must have a positive organisation and actor.');
        }
    }

    public static function fromResolver(
        OrgScopeResolver $resolver,
        Principal $actor,
        int $organisationId,
        string $action,
    ): self {
        [$siteManager, $roles, $unitId, $unitIds] = $resolver->authorise($actor, $organisationId, $action);

        return new self(
            $organisationId,
            $actor->userId,
            self::sorted($roles),
            $siteManager,
            $unitId,
            $siteManager ? self::BASIS_SITE_CAPABILITY : self::BASIS_ROLE_GRANT,
            // Derived from the registry rather than defaulted, which is what
            // this field own docblock always said would happen on the day a
            // provider existed. It is in the digest, so an artefact computed
            // under one policy is never served under another.
            $resolver->disclosureKeyFor($organisationId),
            self::DISCLOSURE_VERSION,
            $unitIds,
        );
    }

    /** Acceptance is already authorised by fresh mailbox proof, not by an org staff role. */
    public static function fromInvitationProof(InvitationProof $proof): self
    {
        return new self($proof->organisationId, $proof->userId, [], false, null, self::BASIS_MANUAL);
    }

    /** A durable, previously-authorised cleanup is bound to its stored tenant, never a request tenant. */
    public static function fromTerminationJob(OrganisationAccessTermination $termination, int $jobId): self
    {
        [$organisationId, $actorId] = $termination->jobAuthority($jobId);

        return new self($organisationId, $actorId, [], false, null, self::BASIS_SYSTEM);
    }

    /**
     * A free organisation sweep, acting on the tenant and the actor its own
     * swept row recorded.
     *
     * The fourth factory and the fourth witness, added for the four free
     * organisation sweeps and for nothing else. It takes the pair rather than
     * a collaborator because {@see OrganisationSweepAuthority} has already
     * read the row and is the only caller; the gate names that one file, so a
     * fifth sweep adds a caller of it rather than a fifth way to reach
     * organisation data.
     *
     * **It is not a system actor and must never become one.** The actor is
     * the administrator whose own recorded action created the row being
     * swept: the person who funded the pool, spent the seat or sent the
     * invitation. A row whose recorded actor is missing produces no scope at
     * all, which is why the caller answers null rather than substituting a
     * zero, and it is the same rule BackgroundActorResolver states for
     * commerce.
     */
    public static function fromSweptRecord(int $organisationId, int $actorId): self
    {
        return new self($organisationId, $actorId, [], false, null, self::BASIS_SYSTEM);
    }

    /**
     * The organisations this scope reaches, in the design's carried shape.
     *
     * One element today and never zero. It is a list rather than the bare id
     * so that the digest, and every consumer written against the design's
     * shape, is already correct on the day a second element is possible.
     *
     * @return list<int>
     */
    public function organisationIds(): array
    {
        return [$this->organisationId];
    }

    /**
     * The units this scope reaches, with descendants already folded in.
     *
     * Empty means the whole organisation rather than nothing, which is the one
     * place an empty list is a widening and is safe: a scope with no unit
     * constraint has already passed an organisation-wide role check.
     *
     * It carried the anchor alone until the review found what that cost. Two
     * things depend on the list being the real reach. The digest is one: a
     * unit moved between a schedule's save and its run changed which people
     * the scope reached and produced a byte-identical digest, so the artefact
     * was served stale under exactly the invariant the digest exists for. The
     * second is agreement: a provider honouring VisibilityScope::ids() saw the
     * anchor while {@see constrainUnit()} saw the whole subtree, which is two
     * reaches for one scope.
     *
     * The fall-back to the anchor is for a scope minted before the resolver
     * expanded anything, which is every scope built from an invitation proof
     * or a termination job; neither carries a unit at all.
     *
     * @return list<int>
     */
    public function unitIds(): array
    {
        if ($this->resolvedUnitIds !== []) {
            return $this->resolvedUnitIds;
        }

        return $this->unitId === null ? [] : [$this->unitId];
    }

    /**
     * The digest of the resolved narrowing, not of the ids.
     *
     * Every input is something that changes the answer. A site manager and an
     * organisation reporter running one report over one organisation produce
     * two artefacts and neither is served under the other's digest; a unit
     * moved between a schedule's save and its run changes the resolved id
     * list, changes the digest, and invalidates the artefact rather than
     * silently serving stale reach.
     *
     * **It identifies the reach and not the reader, deliberately.** actorId is
     * not an input, so two administrators of one organisation holding the same
     * roles produce one digest, which is what lets them share an artefact they
     * are both entitled to every row of. The rule that follows from it is not
     * optional: an artefact whose rows depend on the acting person rather than
     * on their reach must key on this digest **and** the actor id.
     * org_memberships.manager_user_id and LineManagerAudience are exactly that
     * shape, so a "my direct reports" report keyed on the digest alone would
     * be served to a second administrator holding the same role. If actorId is
     * ever hashed in, DIGEST_SHAPE moves to v2 in the same edit, which is what
     * the prefix is for.
     *
     * It is computed rather than stored so it cannot drift from the scope it
     * describes, and it is cheap: one hash over a short string.
     */
    public function digest(): string
    {
        return hash('sha256', implode('', [
            self::DIGEST_SHAPE,
            '|org:', implode(',', $this->organisationIds()),
            '|unit:', implode(',', $this->unitIds()),
            '|basis:', $this->basis,
            '|roles:', implode(',', $this->roles),
            '|disc:', $this->disclosureKey, ':', (string) $this->disclosureVersion,
        ]));
    }

    /** One registered role scope, resolved once; indexed closure joins never enumerate the full tree. */
    public function constrainUnit(\Shondalai\VidyaLMS\Core\Port\Database\Query $query, string $unitColumn): void
    {
        if ($this->unitId !== null) {
            $query->join('org_unit_closure', 'scoped_units', $unitColumn, 'scoped_units.descendant_id')
                ->where('scoped_units.organisation_id', '=', $this->organisationId)
                ->where('scoped_units.ancestor_id', '=', $this->unitId);
        }
    }

    /** Narrow person-owned records to current members of the delegated unit. */
    public function constrainMembers(\Shondalai\VidyaLMS\Core\Port\Database\Query $query, string $userColumn): void
    {
        if ($this->unitId !== null) {
            $query->join('org_memberships', 'scope_members', $userColumn, 'scope_members.user_id')
                ->where('scope_members.organisation_id', '=', $this->organisationId)
                ->whereNotNull('scope_members.active_key');
            $this->constrainUnit($query, 'scope_members.org_unit_id');
        }
    }

    /**
     * Sorted and deduplicated, because the digest is over a set and two
     * scopes that reach the same rows must not differ by the order a query
     * happened to return the role rows in.
     *
     * @param list<string> $roles
     *
     * @return list<string>
     */
    private static function sorted(array $roles): array
    {
        $unique = array_values(array_unique($roles));
        sort($unique);

        return $unique;
    }
}
