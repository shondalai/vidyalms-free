<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Registry\FieldDisclosure\FieldDisclosureRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\ReachesUnits;

/**
 * Host capability AND active local membership AND a permitting role are required.
 *
 * The organisation lookup runs last, after the role predicate, which is the
 * reverse of the order the design writes down. It is deliberate and the
 * substantive property is preserved rather than merely claimed: every refusal
 * on this path is one indistinguishable EntityNotFoundException carrying the
 * same message, so an unknown id, a suspended organisation, an organisation
 * the caller has no role in and one that exists but is not theirs are the same
 * answer with the same body. Running the lookup first would only change which
 * of those identical objects was thrown. The property is asserted rather than
 * intended: the management test compares the two response bodies and not just
 * their statuses.
 *
 * **A site manager reaches a suspended organisation and a delegated
 * administrator does not.** The manage waiver skips the state gate as well as
 * the role predicate, which is right, because somebody has to be able to work
 * on a suspended tenant and the delegated console is exactly what suspension
 * is meant to close. The design's manage-waiver paragraph said only that the
 * org predicate is evaluated for a manage holder, so the waiver is stated here
 * as well: the access log records that read on both anchors with basis
 * site_capability, and that record is what makes it reviewable rather than
 * merely permitted.
 */
final class OrgScopeResolver
{
    /**
     * Where a disclosure policy key is stored.
     *
     * A settings row rather than a column, on the precedent the public mailbox
     * exclusion list and the unit depth bound already set: a site-wide
     * operational value no provider interprets and no report filters on. The
     * per-organisation name is this one with the id appended.
     */
    public const DISCLOSURE_SETTING = 'organisation.field_disclosure';

    /**
     * What a unit-scoped assignment may exercise.
     *
     * This is the reach rule and not the action vocabulary: the vocabulary is
     * the union over the registered roles, and this is the subset of it that
     * makes sense one level down from the organisation. Settings, role
     * assignment and the organisation's own event history belong to the
     * organisation, so a unit manager does not reach them however their role
     * is written.
     *
     * It is the reach of a role that declares nothing, and no longer the reach
     * of every role: a role implementing {@see ReachesUnits} answers for
     * itself, which is what lets a right a package introduced be exercised one
     * level down instead of being refused by a literal the package cannot
     * edit. The four free roles declare nothing and therefore reach exactly
     * this, unchanged.
     */
    public const UNIT_SCOPED_ACTIONS = ['read', 'people', 'learning', 'seats'];

    public function __construct(
        private readonly AccessGuard $guard,
        private readonly OrganisationStore $store,
        private readonly OrgRoleRegistry $roles,
        /**
         * Whatever is recording this request, or nothing. Defaulted so every
         * existing construction site keeps working and so a use case run
         * outside a request records nothing rather than guessing.
         */
        private readonly ?RecordsOrganisationAccess $records = null,
        /**
         * Where a disclosure key is resolved from, or nothing.
         *
         * Trailing and defaulted, so every construction site built before the
         * registry had a provider keeps working and answers the free key. A
         * caller with neither gets org_minimal, which is the deny-most policy
         * and therefore the safe one to be wrong with.
         */
        private readonly ?FieldDisclosureRegistry $disclosures = null,
        private readonly ?Settings $settings = null,
    ) {
    }

    /**
     * Which field disclosure policy this organisation is read under.
     *
     * OrgScope carried the literal org_minimal, and the field own docblock said
     * "the factories resolve it from the registry on the day a provider
     * exists". A provider existed and the literal stayed, so the registry could
     * select nothing: a Business org_declared policy could be registered and
     * never reached, and the pipeline went on omitting every address whatever
     * was installed.
     *
     * Per organisation first, then the site default, then the free key. A key
     * naming a policy this site has no provider for falls back rather than
     * resolving to nothing, because {@see FieldDisclosureRegistry::decideThrough()}
     * omits every field for an unresolvable key and an organisation whose paid
     * policy was uninstalled would otherwise see an empty report rather than
     * the free one.
     */
    public function disclosureKeyFor(int $organisationId): string
    {
        if ($this->disclosures === null || $this->settings === null) {
            return OrgScope::DISCLOSURE_ORG_MINIMAL;
        }

        foreach ([self::DISCLOSURE_SETTING . '.' . $organisationId, self::DISCLOSURE_SETTING] as $name) {
            $key = $this->settings->getString($name);

            if ($key !== '' && $this->disclosures->has($key)) {
                return $key;
            }
        }

        return OrgScope::DISCLOSURE_ORG_MINIMAL;
    }

    /**
     * The rule, thrown, with the resolution published to the request.
     *
     * Publishing is what stopped the access log answering a second question of
     * its own. It used to re-resolve with the default "read" right, so a role
     * permitting only "events" served the event history with a 200 and was
     * recorded as a refusal with no organisation, no role and no digest, which
     * understates a real disclosure in the record that exists to show it.
     */
    public function require(Principal $actor, int $organisationId, string $action = 'read'): OrgScope
    {
        $scope = OrgScope::fromResolver($this, $actor, $organisationId, $action);
        $this->records?->noteScope($scope);

        return $scope;
    }

    /**
     * The rule, thrown, for a read that has no unit anchor to narrow on.
     *
     * Three organisation-wide reads sit behind the `events` right and none of
     * the tables under them carries a unit at all: the seat ledger, its
     * reconciliation and the access cleanup queue. While the free reach literal
     * excluded `events` that was safe by accident, because no unit-scoped
     * assignment could ask. The first role to declare a wider reach through
     * {@see ReachesUnits} made it unsafe: a manager of one floor asked for the
     * whole tenant's ledger and got it, since a query with nothing to narrow on
     * narrows on nothing.
     *
     * So a read whose table has no unit column says so here rather than
     * pretending to be scoped, and a scope carrying a unit is refused with the
     * same indistinguishable answer every other refusal on this path gives. The
     * alternative, dropping the right from the role, would have moved the rule
     * into a package the core cannot see.
     */
    public function requireOrganisationWide(Principal $actor, int $organisationId, string $action = 'read'): OrgScope
    {
        $scope = $this->require($actor, $organisationId, $action);

        if ($scope->unitId !== null) {
            throw self::missing();
        }

        return $scope;
    }

    public function requireSiteManager(Principal $actor): void
    {
        $this->guard->requireAuthenticated($actor);
        $this->guard->requireAny($actor, Capability::MANAGE, Capability::ADMIN);
    }

    /**
     * What the host answers for this person, over the capabilities the
     * registered roles actually name.
     *
     * It is asked with no resource, which is the whole contract: the host says
     * whether this person may be a delegated administrator of something, and
     * the tenant predicate below says of what. The list is derived from the
     * registered providers rather than enumerated, so a package registering a
     * role that operates under a different capability gets that capability
     * asked without a core edit.
     *
     * @return list<string>
     */
    public function hostCapabilities(Principal $actor): array
    {
        $declared = [];

        foreach ($this->roles->all() as $role) {
            foreach ($role->capabilities() as $capability) {
                $declared[$capability] = true;
            }
        }

        $held = [];

        foreach (array_keys($declared) as $capability) {
            if ($this->guard->can($actor, $capability)) {
                $held[] = $capability;
            }
        }

        sort($held);

        return $held;
    }

    /**
     * The rule, answered, with the unit reach resolved rather than deferred.
     *
     * The fourth element is the closure-expanded unit list. It is resolved
     * here, once, because the design's carried unit list already includes
     * descendants and OrgScope was carrying the anchor alone: a unit moved
     * between two resolutions changed the reach and produced a byte-identical
     * digest, so the artefact invalidation the digest exists for did not
     * happen at all.
     *
     * @return array{bool, list<string>, ?int, list<int>}
     */
    public function authorise(Principal $actor, int $organisationId, string $action): array
    {
        $this->guard->requireAuthenticated($actor);
        // Personal learning has no delegated administration rights. Its callers
        // must further narrow assignments and entitlements to this actor.
        if ($action === 'own_learning') {
            if ($this->store->ownMembership($organisationId, $actor->userId) === null) {
                throw self::missing();
            }
            return [false, [], null, []];
        }
        $site = $this->guard->can($actor, Capability::MANAGE) || $this->guard->can($actor, Capability::ADMIN);
        $roleKeys = [];
        $unitId = null;

        if (!$site) {
            // This host question only grants entry to the delegated surface.
            // The authoritative tenant predicate is always evaluated below.
            if (!$this->guard->can($actor, Capability::MANAGE_GROUPS)) {
                throw self::missing();
            }

            $roleKeys = $this->store->activeRoles($organisationId, $actor->userId);
            $roleKeys = array_values(array_filter($roleKeys, fn (string $key): bool => $this->roles->availableFor($key, $organisationId)));
            $allowed = false;

            foreach ($roleKeys as $key) {
                $allowed = $allowed || $this->roles->permits($key, $action, $organisationId);
            }

            if (!$allowed) {
                $unit = $this->store->activeUnitRoles($organisationId, $actor->userId);
                if ($unit !== null) {
                    $unit['roles'] = array_values(array_filter($unit['roles'], fn (string $key): bool => $this->roles->availableFor($key, $organisationId)));
                    foreach ($unit['roles'] as $key) {
                        $allowed = $allowed
                            || ($this->roles->permits($key, $action, $organisationId) && $this->reachesUnits($key, $action));
                    }
                    if ($allowed) {
                        $roleKeys = $unit['roles'];
                        $unitId = $unit['unit_id'];
                    }
                }
                if (!$allowed) {
                    throw self::missing();
                }
            }
        }

        $organisation = $this->store->findForAccess($organisationId);

        if ($organisation === null || (!$site && $organisation['state'] !== 'active')) {
            throw self::missing();
        }

        return [
            $site,
            $roleKeys,
            $unitId,
            $unitId === null ? [] : $this->store->unitSubtree($organisationId, $unitId),
        ];
    }

    /**
     * Whether one role's unit-scoped assignment exercises one right.
     *
     * Asked per role rather than once per request, because reach is the
     * role's own declaration: two roles assigned at the same unit may reach
     * differently, and folding them into one site-wide vocabulary would let
     * one package's declaration widen another package's role.
     */
    public function reachesUnits(string $roleKey, string $action): bool
    {
        if (!$this->roles->has($roleKey)) {
            return false;
        }

        $role = $this->roles->get($roleKey);

        if ($role instanceof ReachesUnits) {
            return \in_array($action, $role->unitScopedActions(), true);
        }

        return \in_array($action, self::UNIT_SCOPED_ACTIONS, true);
    }

    private static function missing(): EntityNotFoundException
    {
        // Identical detail for unknown id, suspended org and unclaimed id.
        return new EntityNotFoundException('No organisation here.');
    }
}
