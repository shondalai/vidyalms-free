<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipEvent;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\ProviderParameters;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Context\OrgMembershipTerminationContext;
use Shondalai\VidyaLMS\Core\Registry\Context\OrgUnitMoveContext;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * Membership never creates learning access; closing it terminates only this
 * relationship's grants.
 *
 * No role key is spelled anywhere in this class, and that is the point rather
 * than tidiness. Every question it used to answer with a literal is now asked
 * of the registry: who owns the organisation is the ownership marker, what
 * rights exist is the union over the registered providers, and who may assign
 * what is a subset comparison over those rights. A role registered by a
 * package therefore behaves like the four the free build ships, which is the
 * acceptance test the whole area is measured by.
 */
final class ManageOrganisationMembers
{
    /** The right to give somebody a membership, which every assignment goes through. */
    private const ACTION_PEOPLE = 'people';

    public function __construct(
        private readonly OrganisationAccess $access,
        private readonly OrganisationStore $store,
        private readonly OrgRoleRegistry $roles,
        private readonly UsersPort $users,
        private readonly TranslatorPort $translator,
        private readonly ?OrganisationAccessTermination $termination = null,
        /** Where the member read publishes the person it resolved; see {@see get()}. */
        private readonly ?RecordsOrganisationAccess $subjects = null,
        /**
         * The two organisation policy seats this use case is the reader for.
         *
         * Trailing and defaulted, on the standing rule, so a harness that
         * builds this with five arguments still builds; absent, the closure
         * behaves as it did before the seams had a reader and the unit move
         * carries everything, which is the free provider's own answer.
         */
        private readonly ?OrgPolicyResolution $policies = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function list(Principal $actor, int $organisationId, int $page = 1, int $perPage = 25, string $state = ''): array
    {
        $scope = $this->access->require($actor, $organisationId);
        OrganisationInput::page($page, $perPage);
        // The empty string is "any state" and is not one of them, which is why
        // it is spelled here rather than added to the vocabulary: a filter word
        // that means "do not filter" has no business in the column's own list.
        if ($state !== '' && !MembershipState::isKnown($state)) {
            throw ValidationException::forField('state', 'Unsupported membership state.');
        }
        $result = $this->store->members($scope, $page, $perPage, $state);
        $names = $this->users->namesFor(array_map(static fn (array $item): int => (int) $item['user_id'], $result['items']));
        foreach ($result['items'] as &$item) {
            $item['user_name'] = $names[(int) $item['user_id']] ?? null;
            // No global learner record or unrelated learning history is exposed.
        }

        return $result;
    }

    /**
     * @param array<string, mixed>     $input
     * @param ?MembershipEventContext  $provenance who is writing this membership and on what evidence
     *
     * @return array<string, mixed>
     */
    public function add(
        Principal $actor,
        int $organisationId,
        array $input,
        ?MembershipEventContext $provenance = null,
    ): array {
        OrganisationInput::only($input, array_merge(
            ['expected_revision', 'user_id', 'role_key', 'org_unit_id', 'role_unit_id'],
            MembershipDirectory::FIELDS,
        ));
        $revision = OrganisationInput::integer($input, 'expected_revision');
        $userId = OrganisationInput::integer($input, 'user_id');
        $role = OrganisationInput::text($input, 'role_key', 60);
        $unitId = $input['org_unit_id'] ?? null;
        $roleUnitId = $input['role_unit_id'] ?? null;
        $directory = MembershipDirectory::fromInput($input);

        return $this->store->database->transactional(function () use (
            $actor,
            $organisationId,
            $revision,
            $userId,
            $role,
            $unitId,
            $roleUnitId,
            $directory,
            $provenance
        ): array {
            $scope = $this->access->require($actor, $organisationId, 'people');
            if (!$scope->siteManager) {
                throw new NotAuthorizedException('Invite this person to join the organisation. Only site managers can add an account directly.');
            }
            $this->assertRole($actor, $scope, $role);
            $this->requireScopedBeneficiary($actor, $organisationId, $userId);
            $unitId = $this->validateUnit($scope, $unitId);
            $roleUnitId = $this->validateRoleUnit($actor, $scope, $role, $roleUnitId);
            $this->termination?->requireSettled($scope, userId: $userId);
            if (!$this->users->exists($userId)) {
                throw ValidationException::forField('user_id', 'The CMS account is unavailable.');
            }
            $next = $this->store->claim($scope, $revision);
            $existing = $this->store->activeMemberId($scope, $userId);
            if ($existing !== null) {
                throw new ConflictException('This person already has an active membership.');
            }
            // The provenance is the caller's own context object and never a
            // key in the request body. A roster load says it is a roster
            // load by constructing one; an API client cannot claim to be
            // one, because there is no input field to claim it with. That
            // is also what keeps the importer contract true: the roster
            // importer writes provisioned_by through this use case rather
            // than updating org_memberships behind it.
            $provenance ??= MembershipEventContext::manual($unitId, $role);
            $id = $this->store->addMember(
                $scope,
                $userId,
                $role,
                $provenance->source,
                $provenance->sourceRef,
                $unitId,
            );
            // The manager check runs against the scope the write is happening
            // in, so a named manager outside this organisation is refused by
            // the same predicate that refuses reading them.
            $this->store->setDirectory($scope, $id, $directory->columnsFor($this->store, $scope, $userId));
            if ($roleUnitId !== null) {
                $this->store->setRole($scope, $id, $role, $roleUnitId);
            }
            $this->store->event(
                $scope,
                $next,
                MembershipEvent::JOINED,
                $userId,
                ['membership_id' => $id, 'role_key' => $role],
                $provenance->inUnit($unitId)->forRole($role),
            );

            return ['organisation_revision' => $next, 'item' => $this->store->member($scope, $id)];
        });
    }

    /**
     * @param array<string, mixed>    $input
     * @param ?MembershipEventContext $provenance who is writing this change and on what evidence
     *
     * @return array<string, mixed>
     */
    public function update(
        Principal $actor,
        int $organisationId,
        int $memberId,
        array $input,
        ?MembershipEventContext $provenance = null,
    ): array {
        return $this->applyUpdate($actor, $organisationId, $memberId, $input, $provenance);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateProvisioned(
        Principal $actor,
        int $organisationId,
        int $memberId,
        array $input,
        string $source,
        int $connectionId,
        string $subject,
    ): array {
        return $this->applyUpdate($actor, $organisationId, $memberId, $input,
            MembershipEventContext::directory($subject),
            [$source, ApplyProvisioningAssertion::membershipReference($connectionId, $subject)]);
    }

    /**
     * @param array<string, mixed> $input
     * @param array{string, string}|null $provisioning
     * @return array<string, mixed>
     */
    private function applyUpdate(
        Principal $actor,
        int $organisationId,
        int $memberId,
        array $input,
        ?MembershipEventContext $provenance,
        ?array $provisioning = null,
    ): array {
        OrganisationInput::only($input, array_merge(
            // role_params is the writer org_role_assignments.params was given a
            // column for and never got. OrgRoleInterface has declared
            // paramsSchema() and currentShape() from its first release, the
            // store has taken a payload since the column landed, and no route
            // on any host could set one: a parameterised role could be
            // registered and never configured. The Pro group leader is the
            // first role with something to say, and this is where it is said.
            ['expected_revision', 'state', 'role_key', 'role_params', 'org_unit_id', 'role_unit_id'],
            MembershipDirectory::FIELDS,
        ));
        $revision = OrganisationInput::integer($input, 'expected_revision');
        $role = array_key_exists('role_key', $input) ? OrganisationInput::text($input, 'role_key', 60) : null;
        $state = $input['state'] ?? null;
        $directory = MembershipDirectory::fromInput($input);
        if (array_key_exists('role_unit_id', $input) && $role === null) {
            throw ValidationException::forField('role_key', 'Choose the role together with its unit scope.');
        }
        // A payload belongs to the role it configures, so it arrives with one.
        // Sending it alone would be sending a shape with nothing to check it
        // against, and the assignment it would land on may be for a different
        // role entirely.
        if (array_key_exists('role_params', $input) && $role === null) {
            throw ValidationException::forField('role_key', 'Choose the role together with its parameters.');
        }
        if (array_key_exists('role_params', $input) && !\is_array($input['role_params'])) {
            throw ValidationException::forField('role_params', 'The role parameters are an object.');
        }
        // Five settable words rather than three. `inactive` and `completed` are
        // Canvas's, and they are the two a roster needs: visible but not
        // participating is a real state and neither `suspended` (a decision
        // about the person) nor `left` (terminal, and never reopened) says it.
        // `invited` stays unsettable, because a caller who could write it by
        // hand could manufacture a pending invitation with no token behind it.
        if ($state !== null && !\in_array($state, MembershipState::SETTABLE, true)) {
            throw ValidationException::forField(
                'state',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'Expected one of: ' . implode(', ', MembershipState::SETTABLE) . '.'
            );
        }
        if ($role === null && $state === null && !array_key_exists('org_unit_id', $input) && $directory->isEmpty()) {
            // Reached only when nothing at all was sent, because role_params
            // without role_key is already refused above.
            throw ValidationException::forField('input', 'Choose a membership state, role or directory attribute.');
        }

        return $this->store->database->transactional(function () use (
            $actor,
            $organisationId,
            $memberId,
            $revision,
            $role,
            $state,
            $input,
            $directory,
            $provenance,
            $provisioning,
        ): array {
            $scope = $this->access->require($actor, $organisationId, 'people');
            if (array_key_exists('org_unit_id', $input)) {
                $this->validateUnit($scope, $input['org_unit_id']);
            }
            $member = $this->store->member($scope, $memberId);
            if ($provisioning !== null && ((int) $member['directory_managed'] !== 1
                || $member['provisioned_by'] !== $provisioning[0] || $member['source_ref'] !== $provisioning[1]
                || $this->roles->holdsOwnership($member['role_keys']))) {
                throw new ConflictException('This connection does not manage this membership.');
            }
            if ($provisioning === null && (int) $member['directory_managed'] === 1 && !$this->ownsOrganisation($scope)) {
                throw new ConflictException('This membership is managed by its provisioning source.');
            }
            if (MembershipState::isClosed((string) $member['state'])) {
                throw new ConflictException('A closed membership cannot be reopened. Add a new membership.');
            }
            if ($role !== null) {
                $this->assertRole($actor, $scope, $role);
                $roleUnit = $this->validateRoleUnit($actor, $scope, $role, $input['role_unit_id'] ?? null);
            }
            /** @var list<string> $oldRoles */
            $oldRoles = $member['role_keys'];
            $wasOwner = $this->roles->holdsOwnership($oldRoles);
            if ($wasOwner && !$this->ownsOrganisation($scope)) {
                throw new NotAuthorizedException('Only an organisation owner can change another owner.');
            }
            $next = $this->store->claim($scope, $revision);
            if ($provisioning === null && (int) $member['directory_managed'] === 1) {
                // An explicit owner override detaches this membership so a later
                // sign-in cannot silently restore the revoked role or state.
                $this->store->database->update('org_memberships', ['directory_managed' => 0, 'provisioned_by' => 'manual', 'source_ref' => null],
                    ['id' => $memberId, 'organisation_id' => $organisationId]);
            }
            if (
                $wasOwner && $member['state'] === MembershipState::ACTIVE
                && (($role !== null && !$this->roles->holdsOwnership([$role]))
                    || ($state !== null && $state !== MembershipState::ACTIVE))
                && $this->store->activeOwnerCount($scope, $this->roles->ownershipKeys()) <= 1
            ) {
                throw new ConflictException('Assign another active owner before changing the last owner.');
            }
            if (
                $state === MembershipState::ACTIVE && $member['state'] !== MembershipState::ACTIVE
                && $this->store->activeMemberId($scope, (int) $member['user_id']) !== null
            ) {
                throw new ConflictException('This person already has another active membership.');
            }
            if ($role !== null) {
                // The payload is validated against the provider's own schema
                // and stored under the provider's own shape version, so a role
                // that later changes shape can tell which one wrote the row.
                // A role with a closed empty schema refuses every key, which is
                // what all four free roles do and why they store NULL.
                $provider = $this->roles->get($role);
                $params = \array_key_exists('role_params', $input) && \is_array($input['role_params'])
                    ? ProviderParameters::validate($provider->paramsSchema(), $input['role_params'], 'role_params')
                    : null;
                $this->store->setRole(
                    $scope,
                    $memberId,
                    $role,
                    $roleUnit ?? null,
                    $params === null ? null : StoredShape::wrap($params, $provider->currentShape()),
                    $provider->currentShape(),
                );
            }
            $moveKey = null;
            if (array_key_exists('org_unit_id', $input)) {
                $moveKey = $this->applyUnitMove($scope, $memberId, $member, $input['org_unit_id']);
                $this->store->database->update(
                    'org_memberships',
                    ['org_unit_id' => $input['org_unit_id']],
                    ['organisation_id' => $organisationId, 'id' => $memberId]
                );
            }
            $this->store->setDirectory(
                $scope,
                $memberId,
                $directory->columnsFor($this->store, $scope, (int) $member['user_id'])
            );
            $closureKey = null;
            if (\is_string($state)) {
                if ($state === MembershipState::ACTIVE) {
                    $this->termination?->requireSettled($scope, userId: (int) $member['user_id']);
                }
                $this->store->setMemberState($scope, $memberId, (int) $member['user_id'], $state);
                if ($state !== MembershipState::ACTIVE) {
                    // The closure seam, which had no reader at all. The
                    // published miss direction is fail closed: a site with no
                    // registered policy cannot close a membership, rather than
                    // closing it under a rule nobody chose.
                    $policy = $this->policies?->terminationFor($organisationId);

                    if ($this->policies !== null && $policy === null) {
                        throw new ConflictException(
                            'No membership termination policy is installed, so this membership cannot be closed.'
                        );
                    }

                    $closureKey = $policy?->key();
                    $releases = $policy === null || $policy->releasesAccess(OrgMembershipTerminationContext::of(
                        $organisationId,
                        $memberId,
                        (int) $member['user_id'],
                        (string) $member['state'],
                        $state,
                        $this->store->instant(),
                    ));

                    if ($releases) {
                        $cleanup = $this->termination?->execute(
                            $scope,
                            $next,
                            'org_membership_' . $state,
                            userId: (int) $member['user_id']
                        );
                    }
                }
            }
            // The policies that decided, named on the membership event, because
            // a decision nothing records is a decision nobody can audit.
            $policyKeys = array_filter([
                'membership_termination_key' => $closureKey,
                'unit_move_key' => $moveKey,
            ], static fn (?string $value): bool => $value !== null);
            $this->recordUpdate(
                $scope,
                $next,
                $memberId,
                $member,
                $oldRoles,
                $role,
                $state,
                $input,
                $directory,
                $provenance ?? MembershipEventContext::manual(),
                $policyKeys,
            );

            // The payload is read back beside the row rather than folded into
            // it, because the member row is the console's list line and a
            // payload is the detail one screen asks for. It is also what gives
            // OrganisationStore::roleParams() a caller: a reader with none is a
            // column nobody can see they wrote.
            $item = $this->store->member($scope, $memberId);

            if ($role !== null) {
                $item['role_params'] = $this->store->roleParams($scope, $memberId, $role);
            }

            return ['organisation_revision' => $next, 'item' => $item]
                + (isset($cleanup) ? ['access_state' => 'disabled', 'cleanup' => $cleanup] : []);
        });
    }

    /**
     * One event row per distinguishable fact, in place of the single
     * `member.updated` row that used to carry all of them.
     *
     * Four questions, asked in the order a reader would ask them, and each one
     * that answers yes writes its own row against the same organisation
     * revision. A request that moves somebody's unit and grants them a role
     * writes two rows, because they are two facts with two audiences and two
     * retention answers, and because the export route filters on `event_key`:
     * a filter over one word that meant seven things could not answer "who was
     * given the reporting role last quarter" without decoding every payload.
     *
     * The details payload of every row is the same one the coarse event
     * carried, unchanged, so nothing that already reads it loses anything. What
     * is new is that the row is now findable by what happened to it.
     *
     * A request that changed nothing material writes nothing. It is possible:
     * a patch naming the role the person already holds passes validation, and
     * an append-only stream that recorded a row for it would be recording that
     * something happened when nothing did. The claimed revision is the record
     * that somebody touched the membership.
     *
     * @param array<string, mixed>  $member     the row as it was before the write
     * @param list<string>          $oldRoles   the roles it held before the write
     * @param array<string, mixed>  $input      the request, for the keys it actually carried
     * @param array<string, string> $policyKeys the organisation policies that decided this write
     */
    private function recordUpdate(
        OrgScope $scope,
        int $revision,
        int $memberId,
        array $member,
        array $oldRoles,
        ?string $role,
        mixed $state,
        array $input,
        MembershipDirectory $directory,
        MembershipEventContext $context,
        array $policyKeys = [],
    ): void {
        $subjectId = (int) $member['user_id'];
        $unitNamed = array_key_exists('org_unit_id', $input);
        $unit = $unitNamed ? $input['org_unit_id'] : $member['org_unit_id'];
        $details = $policyKeys + [
            'membership_id' => $memberId, 'previous_state' => $member['state'], 'state' => $state ?? $member['state'],
            'previous_roles' => $oldRoles, 'role_key' => $role,
            'org_unit_id' => $unit,
            'role_unit_id' => $unitNamed || array_key_exists('role_unit_id', $input)
                ? ($input['role_unit_id'] ?? null)
                : $member['role_unit_id'],
        ];
        $unitId = \is_int($unit) ? $unit : (is_numeric($unit) ? (int) $unit : null);
        $here = $context->inUnit($unitId);

        if ($role !== null && !\in_array($role, $oldRoles, true)) {
            foreach ($oldRoles as $displaced) {
                $this->store->event(
                    $scope,
                    $revision,
                    MembershipEvent::ROLE_REVOKED,
                    $subjectId,
                    $details,
                    $here->forRole($displaced),
                );
            }

            $this->store->event(
                $scope,
                $revision,
                MembershipEvent::ROLE_GRANTED,
                $subjectId,
                $details,
                $here->forRole($role),
            );
        }

        if ($unitNamed && (string) ($member['org_unit_id'] ?? '') !== (string) ($unit ?? '')) {
            $this->store->event($scope, $revision, MembershipEvent::UNIT_CHANGED, $subjectId, $details, $here);
        }

        if (\is_string($state) && $state !== $member['state']) {
            $this->store->event(
                $scope,
                $revision,
                MembershipEvent::forStateChange((string) $member['state'], $state),
                $subjectId,
                $details,
                $here,
            );
        }

        if (!$directory->isEmpty()) {
            $this->store->event(
                $scope,
                $revision,
                MembershipEvent::ATTRIBUTES_UPDATED,
                $subjectId,
                $details,
                $here,
            );
        }
    }

    /**
     * The subset rule, applied identically here, in the invitation and in
     * anything else that ever names a role.
     *
     * A caller may assign only a role whose rights and effective capabilities
     * are a subset of their own, and a role carrying any right at all may be
     * assigned only by somebody who holds the right to assign roles. Without
     * it, an administrator who may add people can upload a row naming a higher
     * role and acquire rights the product says they may not have, one of which
     * is the retention policy over the log of their own reads.
     *
     * The refusal names no role and lists no alternative, because a caller who
     * may not assign a role has no business learning which roles exist.
     */
    public function assertRole(Principal $actor, OrgScope $scope, string $role): void
    {
        if (!$this->roles->has($role)) {
            throw ValidationException::forField('role_key', 'The role provider is not installed.');
        }
        if (!\in_array($role, $this->assignableRoles($actor, $scope), true)) {
            throw new NotAuthorizedException('Only an organisation owner can assign administrator or reporting roles.');
        }
    }

    /**
     * The role keys this caller may actually assign here.
     *
     * Two gates rather than one. Assigning happens through the membership
     * routes, so a caller who cannot manage people assigns nothing at all, and
     * a role catalogue that said otherwise would offer a control the route
     * refuses. The registry then applies the subset rule over what is left.
     *
     * @return list<string>
     */
    private function assignableRoles(Principal $actor, OrgScope $scope): array
    {
        $mayManagePeople = $scope->siteManager
            || \in_array(self::ACTION_PEOPLE, $this->roles->actionsOf($scope->roles, $scope->organisationId), true);

        if (!$mayManagePeople) {
            return [];
        }

        return $this->roles->assignableBy($this->access->roleContext($actor, $scope));
    }

    /** Whether this caller owns the organisation, or stands above it as a site manager. */
    private function ownsOrganisation(OrgScope $scope): bool
    {
        return $scope->siteManager || $this->roles->holdsOwnership($scope->roles);
    }

    public function requireVisibleMember(Principal $actor, int $orgId, int $memberId): void
    {
        $this->store->member($this->access->require($actor, $orgId, 'people'), $memberId);
    }

    /**
     * One membership, read through the same scope the list read uses.
     *
     * It publishes the person it resolved onto the active organisation, which
     * is what fills admin_access_log.subject_user_id. Resolving a membership
     * to a person is a query, and the middleware above dispatch has neither
     * the store nor any business running one; the handler that already loaded
     * the row does.
     *
     * @return array<string, mixed>
     */
    public function get(Principal $actor, int $orgId, int $memberId): array
    {
        $member = $this->store->member($this->access->require($actor, $orgId, 'people'), $memberId);
        $this->subjects?->noteSubject((int) ($member['user_id'] ?? 0));

        return $member;
    }

    public function requireScopedBeneficiary(Principal $actor, int $orgId, int $userId): void
    {
        $scope = $this->access->require($actor, $orgId, 'people');
        if ($scope->unitId === null) {
            return;
        }
        $existing = $this->store->database->selectOne(\Shondalai\VidyaLMS\Core\Port\Database\Query::from('org_memberships')
            ->where('organisation_id', '=', $orgId)->where('user_id', '=', $userId)->where('state', '=', 'active'));
        if ($existing !== null) {
            $this->store->member($scope, (int) $existing['id']);
        }
    }

    /**
     * Runs the unit-move seam over the one thing a move can silently take
     * away: the unit a role is anchored on.
     *
     * The free build repointed nothing, so a unit manager moved from Bristol
     * to Leeds went on managing Bristol, holding a role scoped to a unit they
     * had left. The policy decides, `carry_everything` repoints the anchor at
     * the unit the member is moving to, and a policy that answers no drops the
     * anchor to the organisation, which leaves the role reaching exactly what
     * its own declaration allows and no more.
     *
     * The published miss direction is a normal path rather than a refusal: an
     * unresolvable policy carries everything and the substitution is recorded
     * on the membership event as `unresolved`.
     *
     * @param array<string, mixed> $member the row as it was before the write
     *
     * @return string|null the policy key to record, or null when nothing moved
     */
    private function applyUnitMove(OrgScope $scope, int $memberId, array $member, mixed $toUnitId): ?string
    {
        $from = $member['org_unit_id'] === null ? null : (int) $member['org_unit_id'];
        $to = \is_int($toUnitId) ? $toUnitId : null;

        if ($from === $to) {
            return null;
        }

        $assignment = $this->store->database->selectOne(
            OrgScopedReader::from($scope, 'org_role_assignments')
                ->where('membership_id', '=', $memberId)
                ->where('scope_type', '=', 'unit')
        );
        $policy = $this->policies?->unitMoveFor($scope->organisationId);
        $key = $policy?->key() ?? 'unresolved';

        if ($assignment === null) {
            // Nothing is anchored on a unit, so there is nothing to decide.
            // The key is still recorded, because "which policy governed this
            // move" is the question the event exists to answer.
            return $key;
        }

        $carries = $policy === null || $policy->carriesUnitScopedRole(OrgUnitMoveContext::of(
            $scope->organisationId,
            $memberId,
            (int) $member['user_id'],
            $from,
            $to,
            (string) $assignment['role_key'],
            $this->store->instant(),
        ));
        $values = $carries && $to !== null
            ? ['scope_type' => 'unit', 'scope_id' => $to]
            : ['scope_type' => 'organisation', 'scope_id' => $scope->organisationId];

        $this->store->database->update(
            'org_role_assignments',
            $values,
            ['organisation_id' => $scope->organisationId, 'id' => (int) $assignment['id']]
        );

        return $key;
    }

    private function validateUnit(OrgScope $scope, mixed $unitId): ?int
    {
        if ($unitId === null) {
            if ($scope->unitId !== null) {
                throw new NotAuthorizedException('A unit-scoped administrator must select a unit within their scope.');
            }
            return null;
        }
        if (!\is_int($unitId) || $unitId < 1) {
            throw ValidationException::forField('org_unit_id', 'Select a unit in this organisation or no unit.');
        }
        $unit = ManageOrganisationUnits::find($this->store, $scope, $unitId);
        if ($unit['state'] !== 'active') {
            throw new ConflictException('Only active units can receive memberships.');
        }

        return $unitId;
    }

    private function validateRoleUnit(Principal $actor, OrgScope $scope, string $role, mixed $unitId): ?int
    {
        if ($unitId === null) {
            return null;
        }
        if ($scope->unitId !== null || !$this->ownsOrganisation($scope)) {
            throw new NotAuthorizedException('Only organisation owners can set delegated role scopes.');
        }
        // Two refusals, and neither names a key. A role that owns the
        // organisation cannot be scoped below it, because ownership is of the
        // whole tenant. A role carrying no right has nothing a unit could
        // narrow, so scoping it would store a reach that means nothing.
        if ($this->roles->holdsOwnership([$role]) || ($this->roles->has($role) && $this->roles->get($role)->actions() === [])) {
            throw ValidationException::forField('role_unit_id', 'Choose an administrative or reporting role for a unit scope.');
        }

        return $this->validateUnit($scope, $unitId);
    }

    /** @return array<string, mixed> */
    public function roleCatalogue(Principal $actor, int $organisationId): array
    {
        $scope = $this->access->require($actor, $organisationId);
        $assignable = $this->assignableRoles($actor, $scope);
        $rows = $this->roles->catalogue($scope->organisationId);
        foreach ($rows as &$row) {
            $row['label'] = $this->translator->translate($row['label']);
            $row['assignable'] = \in_array($row['key'], $assignable, true);
        }
        unset($row);

        // The vocabulary is the union over the registered providers rather
        // than a literal list, so a package that introduces a right sees the
        // console offer it. A site manager holds the whole vocabulary, which
        // is a scoped answer and not a bypass: a right no registered role
        // names is held by nobody.
        $held = $scope->siteManager ? $this->roles->actions() : $this->roles->actionsOf($scope->roles, $scope->organisationId);
        $actions = [];
        foreach ($this->roles->actions() as $action) {
            if ($scope->unitId !== null && !\in_array($action, OrgScopeResolver::UNIT_SCOPED_ACTIONS, true)) {
                continue;
            }
            if (\in_array($action, $held, true)) {
                $actions[] = $action;
            }
        }

        // The published miss direction for OrgRole is that the membership row
        // survives, the person resolves to no capabilities, and the console
        // names the missing provider. The first two fall out of the registry;
        // the third is this, because catalogue() iterates the providers and a
        // key with no provider therefore appears nowhere at all.
        $missing = array_values(array_filter(
            $this->store->heldRoleKeys($scope),
            fn (string $key): bool => !$this->roles->has($key),
        ));

        return ['items' => $rows, 'missing_providers' => $missing, 'access' => [
            'site_manager' => $scope->siteManager, 'actions' => $actions, 'unit_id' => $scope->unitId,
        ]];
    }

    /** @return array<string, mixed> */
    public function events(Principal $actor, int $organisationId, int $page = 1, int $perPage = 25): array
    {
        OrganisationInput::page($page, $perPage);

        return $this->store->events($this->access->require($actor, $organisationId, 'events'), $page, $perPage);
    }
}
