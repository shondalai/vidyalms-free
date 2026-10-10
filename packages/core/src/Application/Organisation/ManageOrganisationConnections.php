<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\ConnectionProtocol;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\HostGroupIssuer;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IdentityLinkState;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IssuerStatus;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingBound;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingRule;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\MappingRuleSet;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\OrgConnection;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\OrgIdentityLink;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\IdentityKey;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipEvent;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgIdentityStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedCompleteness;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\IdentityMapper\IdentityMapperRegistry;
use Shondalai\VidyaLMS\Core\Registry\IdentityMapper\TerminatesProtocol;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * Connections and the identity links they produce.
 *
 * Two rules run through every method here and both are the same rule seen from
 * two ends.
 *
 * **An organisation writes its own row and a site-scoped principal decides
 * whether anybody should believe it.** An organisation's settings holder
 * creates the connection, names the issuer and writes the rules. Only a
 * site-scoped principal moves it to verified, marks it authoritative or
 * enables account creation, and each of those three is recorded on the
 * membership event stream with the actor. Without that split, an
 * administrator of one organisation could record another organisation's real
 * issuer, write a rule matching a common group name, and take over every
 * person who signs in through it, with no administrator of the other
 * organisation involved at any point.
 *
 * **A rule names a target inside the connection's own organisation and
 * nowhere else, enforced twice.** Here at save, and again in the evaluator at
 * evaluation, because a stored row outlives the authorisation that wrote it.
 * Deny by default is no defence against this one, because the rule that fires
 * is the one the attacker wrote.
 */
final class ManageOrganisationConnections
{
    /** The right that authors a connection. It is the organisation's own settings right. */
    public const ACTION = 'settings';

    /** The right that reads one. */
    public const READ_ACTION = 'read';

    /** The most links one page returns. */
    public const MAX_LINKS = 100;

    /** The stored shape of the dialect configuration bag. */
    public const OVERRIDES_SHAPE = 1;

    public function __construct(
        private readonly OrganisationAccess $access,
        private readonly OrgIdentityStore $store,
        private readonly OrganisationStore $organisations,
        private readonly OrgRoleRegistry $roles,
        private readonly IdentityMapperRegistry $mappers,
        private readonly UsersPort $users,
    ) {
    }

    // ── Connections ──────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    public function connections(Principal $actor, int $orgId): array
    {
        $scope = $this->access->requireOrganisationWide($actor, $orgId, self::READ_ACTION);

        return array_map(
            fn (OrgConnection $connection): array => $this->present($connection),
            $this->store->connections($scope)
        );
    }

    /**
     * Records a claim on an issuer.
     *
     * The row lands pending, always, whoever writes it: an organisation
     * claiming an issuer has proved nothing by claiming it, and a row that
     * arrived verified would make the ceremony optional.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function create(Principal $actor, int $orgId, array $input): array
    {
        OrganisationInput::only($input, [
            'issuer',
            'dialect',
            'protocol',
            'secret_ref',
            'mapping_rules',
            'attribute_overrides',
        ]);
        $scope = $this->access->require($actor, $orgId, self::ACTION);
        $issuer = $this->issuer($input['issuer'] ?? null, $scope);
        $dialect = $this->dialect($input['dialect'] ?? null);
        $protocol = $this->protocol($input['protocol'] ?? null, $dialect);

        foreach ($this->store->connections($scope) as $existing) {
            if ($existing->issuer === $issuer) {
                throw new ConflictException('This organisation already claims that issuer.');
            }
        }

        $rules = $this->rulesFor($scope, $input['mapping_rules'] ?? null);

        $id = $this->store->insertConnection([
            'organisation_id' => $scope->organisationId,
            'protocol' => $protocol,
            'dialect' => $dialect,
            'issuer' => $issuer,
            'issuer_status' => IssuerStatus::PENDING,
            'verified_issuer_key' => null,
            'authoritative' => 0,
            'jit_enabled' => 0,
            'default_completeness' => AssertedCompleteness::PARTIAL,
            'secret_ref' => $this->secretRef($input['secret_ref'] ?? null),
            'mapping_rules' => json_encode($rules->toStored(), JSON_THROW_ON_ERROR),
            'mapping_rules_version' => MappingRuleSet::SHAPE_VERSION,
            'attribute_overrides' => json_encode(
                StoredShape::wrap($this->overrides($input['attribute_overrides'] ?? null), self::OVERRIDES_SHAPE),
                JSON_THROW_ON_ERROR,
            ),
            'attribute_overrides_version' => self::OVERRIDES_SHAPE,
            'state' => 'active',
            'created_by' => $scope->actorId,
        ]);

        $this->record($scope, 'connection_claimed', ['connection_id' => $id, 'issuer' => $issuer]);

        return $this->present($this->require($scope, $id));
    }

    /**
     * The organisation's own edit: the dialect, the rules, the secret
     * reference and the state.
     *
     * It cannot reach issuer_status, authoritative or jit_enabled. Those three
     * are the site-scoped transition below, and an organisation that could
     * write them here could write them.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function update(Principal $actor, int $orgId, int $connectionId, array $input): array
    {
        // The authorisation runs before the body is read, so every refusal on
        // this route is still one indistinguishable not-found. Validating first
        // would answer a caller who may not see this organisation with a 422
        // about their body, which is a different answer from the one everybody
        // else gets and therefore a signal.
        $scope = $this->access->require($actor, $orgId, self::ACTION);
        OrganisationInput::only($input, [
            'expected_revision',
            'dialect',
            'secret_ref',
            'state',
            'mapping_rules',
            'attribute_overrides',
            // Named so that writing one is a 422 that says why, rather than
            // the silent drop an allowlist would otherwise produce.
            'issuer_status',
            'authoritative',
            'jit_enabled',
            'verified_issuer_key',
        ]);
        foreach (['issuer_status', 'authoritative', 'jit_enabled', 'verified_issuer_key'] as $reserved) {
            if (\array_key_exists($reserved, $input)) {
                throw ValidationException::forField(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $reserved,
                    'Only a site manager decides whether a connection is believed.'
                );
            }
        }

        // The revision claim, which this write had no equivalent of. Every
        // other organisation write takes one; this one carries mapping_rules,
        // which is the object that decides who gets access, so two
        // administrators editing a connection at once silently kept whichever
        // save landed second and the other one's rules were gone with no
        // conflict anywhere.
        $revision = OrganisationInput::integer($input, 'expected_revision');
        // The lookup runs before the claim, so a foreign connection is still
        // one indistinguishable not-found rather than a revision conflict that
        // tells the caller the row exists somewhere.
        $connection = $this->require($scope, $connectionId);
        // Approval covers the configuration that interprets the identity.
        // A trusted or delegated connection cannot be reconfigured by its
        // tenant while retaining the site manager's approval.
        if (
            ($connection->issuerStatus === IssuerStatus::VERIFIED || $connection->delegatedFromConnectionId !== null)
            && array_intersect(['dialect', 'secret_ref', 'mapping_rules', 'attribute_overrides'], array_keys($input)) !== []
        ) {
            $this->access->requireSiteManager($actor);
        }
        $this->organisations->claim($scope, $revision);
        $values = [];

        if (\array_key_exists('dialect', $input)) {
            $values['dialect'] = $this->dialect($input['dialect']);
        }

        if (\array_key_exists('secret_ref', $input)) {
            $values['secret_ref'] = $this->secretRef($input['secret_ref']);
        }

        if (\array_key_exists('state', $input)) {
            $state = \is_string($input['state']) ? $input['state'] : '';

            if (!\in_array($state, ['active', 'archived'], true)) {
                throw ValidationException::forField('state', 'A connection is active or archived.');
            }

            $values['state'] = $state;
        }

        if (\array_key_exists('mapping_rules', $input)) {
            $rules = $this->rulesFor($scope, $input['mapping_rules']);
            $values['mapping_rules'] = json_encode($rules->toStored(), JSON_THROW_ON_ERROR);
            $values['mapping_rules_version'] = MappingRuleSet::SHAPE_VERSION;
        }

        if (\array_key_exists('attribute_overrides', $input)) {
            // The column, the version column and both adapter reads shipped
            // with no writer anywhere, so the free host-group mapper own
            // documented exclude_group_ids setting could never be configured on
            // a real site. It is a dialect configuration bag and it is stored
            // versioned, like every other stored JSON in this area.
            $values['attribute_overrides'] = json_encode(
                StoredShape::wrap($this->overrides($input['attribute_overrides']), self::OVERRIDES_SHAPE),
                JSON_THROW_ON_ERROR,
            );
            $values['attribute_overrides_version'] = self::OVERRIDES_SHAPE;
        }

        if ($values !== []) {
            $this->store->updateConnection($scope, $connection->id, $values);
            $this->record($scope, 'connection_updated', [
                'connection_id' => $connection->id,
                'fields' => array_keys($values),
            ]);
        }

        return $this->present($this->require($scope, $connection->id));
    }

    /**
     * The site-scoped transition: verify, fail or revoke a claim, and decide
     * whether the connection is authoritative and whether it may create
     * accounts.
     *
     * Three protections, and each one closes a hole the others do not:
     *
     * - a site-scoped principal, checked before anything is read;
     * - the state machine, so a revoked claim does not quietly come back and a
     *   verification is not applied to a row in a state that cannot take it;
     * - and the global unique index on verified_issuer_key underneath, so two
     *   organisations racing to verify one issuer end with one of them
     *   refused by the database rather than by a check that ran first.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function decide(Principal $actor, int $orgId, int $connectionId, array $input): array
    {
        OrganisationInput::only($input, [
            'issuer_status',
            'authoritative',
            'jit_enabled',
            'default_completeness',
        ]);
        $this->access->requireSiteManager($actor);
        $scope = $this->access->require($actor, $orgId, self::READ_ACTION);
        $connection = $this->require($scope, $connectionId);
        $values = [];
        $details = ['connection_id' => $connection->id, 'issuer' => $connection->issuer];

        if (\array_key_exists('issuer_status', $input)) {
            $status = \is_string($input['issuer_status']) ? $input['issuer_status'] : '';

            if (!IssuerStatus::isKnown($status)) {
                throw ValidationException::forField('issuer_status', 'Unknown issuer status.');
            }

            if ($status !== $connection->issuerStatus && !IssuerStatus::permits($connection->issuerStatus, $status)) {
                throw new ConflictException(sprintf(
                    'An issuer claim cannot move from %s to %s.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $connection->issuerStatus,
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $status
                ));
            }

            if ($status === IssuerStatus::VERIFIED) {
                // Re-checked here as well as at save, so a row stored before
                // this rule existed cannot be verified into the lock it was
                // written to cause. The site manager sees an opaque string and
                // has no other signal that it belongs to another tenant.
                $this->refuseAnotherOrganisationsLocalIssuer($connection->issuer, $scope);
                $holder = $this->store->verifiedHolderOf($connection->issuer);

                if ($holder !== null && $holder->id !== $connection->id) {
                    throw new ConflictException('Another organisation already holds that issuer verified.');
                }
            }

            $values['issuer_status'] = $status;
            $values['verified_issuer_key'] = IssuerStatus::verifiedKeyFor($status, $connection->issuer);
            $values['issuer_verified_at'] = $status === IssuerStatus::VERIFIED
                ? $this->organisations->now()
                : null;
            $values['issuer_verified_by'] = $status === IssuerStatus::VERIFIED ? $scope->actorId : null;
            $details['issuer_status'] = $status;
        }

        foreach (['authoritative', 'jit_enabled'] as $flag) {
            if (!\array_key_exists($flag, $input)) {
                continue;
            }

            if (!\is_bool($input[$flag])) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($flag, 'This is true or false.');
            }

            $values[$flag] = $input[$flag] ? 1 : 0;
            $details[$flag] = $input[$flag];
        }

        if (\array_key_exists('default_completeness', $input)) {
            $completeness = \is_string($input['default_completeness']) ? $input['default_completeness'] : '';

            if (!AssertedCompleteness::isKnown($completeness)) {
                throw ValidationException::forField('default_completeness', 'Unknown completeness.');
            }

            $values['default_completeness'] = $completeness;
            $details['default_completeness'] = $completeness;
        }

        if ($values === []) {
            throw ValidationException::forField('issuer_status', 'Nothing was decided.');
        }

        $this->store->updateConnection($scope, $connection->id, $values);
        $this->record($scope, MembershipEvent::GOVERNANCE_CHANGED, $details);

        return $this->present($this->require($scope, $connection->id));
    }

    /**
     * Attaches this connection to a parent's verified connection, or detaches
     * it.
     *
     * A holding company whose subsidiaries are separate organisations on one
     * identity tenant is the case this exists for. The alternative, two
     * organisations both verifying one issuer, is exactly what the global
     * unique index on `verified_issuer_key` refuses, so without delegation a
     * subsidiary either shares the parent's organisation or cannot use the
     * tenant at all.
     *
     * **Site scoped, like the decision route beside it, and for the same
     * reason.** Delegation borrows somebody else's proof. If an organisation
     * could grant itself a parent's verified connection, an administrator of
     * one subsidiary could attach to a sibling's issuer and provision through
     * a claim their own organisation never proved. The root check bounds it to
     * one corporate group; the site-manager check is what stops a member of
     * that group helping themselves.
     *
     * Four refusals, and each closes a hole the others do not:
     *
     * - a parent outside this organisation's own root, which is another
     *   customer entirely;
     * - a parent whose issuer is not verified, because delegating to an
     *   unproved claim launders it into a proved one;
     * - a parent that is itself delegated, because a chain has no verified
     *   end that a reader can find in one hop;
     * - an issuer that is not the parent's, because a connection that borrows
     *   a proof for a different issuer is a connection claiming an issuer
     *   nobody proved.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function delegate(Principal $actor, int $orgId, int $connectionId, array $input): array
    {
        // The site grant first, before the body is read and before anything is
        // looked up. A caller who may not make this decision must be told so
        // whatever they sent, and a validation failure answered ahead of the
        // authorisation would tell them their body was wrong about a decision
        // they were never allowed to make.
        $this->access->requireSiteManager($actor);
        OrganisationInput::only($input, ['delegated_from_connection_id']);

        if (!\array_key_exists('delegated_from_connection_id', $input)) {
            throw ValidationException::forField('delegated_from_connection_id', 'Name a parent connection, or null.');
        }

        $scope = $this->access->require($actor, $orgId, self::READ_ACTION);
        $connection = $this->require($scope, $connectionId);
        $parentId = $input['delegated_from_connection_id'];

        if ($parentId === null) {
            $this->store->updateConnection($scope, $connection->id, ['delegated_from_connection_id' => null]);
            $this->record($scope, MembershipEvent::GOVERNANCE_CHANGED, [
                'connection_id' => $connection->id,
                'issuer' => $connection->issuer,
                'delegated_from_connection_id' => null,
            ]);

            return $this->present($this->require($scope, $connection->id));
        }

        if (!\is_int($parentId) || $parentId < 1) {
            throw ValidationException::forField('delegated_from_connection_id', 'A connection id is a positive integer.');
        }

        if ($parentId === $connection->id) {
            throw new ConflictException('A connection cannot delegate to itself.');
        }

        $organisation = $this->organisations->organisation($scope);
        $rootId = (int) ($organisation['root_id'] ?? 0);
        $parent = $this->store->connectionUnderRoot($rootId, $parentId);

        if ($parent === null) {
            // The same 404 a foreign connection gets everywhere else in this
            // class: a parent under another root and a parent that does not
            // exist read identically, so the route cannot be used to discover
            // another customer's connection ids.
            throw new EntityNotFoundException('Connection not found.');
        }

        if ($parent->issuerStatus !== IssuerStatus::VERIFIED) {
            throw new ConflictException('A parent connection must hold its issuer verified.');
        }

        if ($parent->delegatedFromConnectionId !== null) {
            throw new ConflictException('A delegated connection cannot itself be delegated from.');
        }

        if ($parent->issuer !== $connection->issuer) {
            throw new ConflictException('A delegated connection carries the parent\'s own issuer.');
        }

        $this->store->updateConnection($scope, $connection->id, [
            'delegated_from_connection_id' => $parent->id,
        ]);
        $this->record($scope, MembershipEvent::GOVERNANCE_CHANGED, [
            'connection_id' => $connection->id,
            'issuer' => $connection->issuer,
            'delegated_from_connection_id' => $parent->id,
            'delegated_from_organisation_id' => $parent->organisationId,
        ]);

        return $this->present($this->require($scope, $connection->id));
    }

    // ── Identity links ───────────────────────────────────────────────────

    /**
     * This organisation's identity links, optionally narrowed to the ones
     * waiting for somebody.
     *
     * @return list<array<string, mixed>>
     */
    public function links(Principal $actor, int $orgId, ?string $state = null, int $limit = 50): array
    {
        $scope = $this->access->requireOrganisationWide($actor, $orgId, self::READ_ACTION);

        if ($state !== null && !IdentityLinkState::isKnown($state)) {
            throw ValidationException::forField('state', 'Unknown link state.');
        }

        return array_map(
            static fn (OrgIdentityLink $link): array => $link->toArray(),
            $this->store->links($scope, $state, min(self::MAX_LINKS, max(1, $limit)))
        );
    }

    /**
     * An administrator resolving a conflict or confirming a pending link.
     *
     * **No merge happens here and none happens anywhere.** A conflict is
     * resolved by naming which account the identity is, or by revoking the
     * link entirely; two existing people are never joined into one, because a
     * merge is not reversible and correcting duplicated people afterwards is
     * the failure this whole area is designed against.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function resolveLink(Principal $actor, int $orgId, int $linkId, array $input): array
    {
        OrganisationInput::only($input, ['state', 'user_id']);
        $scope = $this->access->require($actor, $orgId, self::ACTION);
        $link = $this->store->link($scope, $linkId) ?? throw new EntityNotFoundException('Identity link not found.');
        $state = \is_string($input['state'] ?? null) ? $input['state'] : '';

        if (!\in_array($state, [IdentityLinkState::ACTIVE, IdentityLinkState::REVOKED], true)) {
            throw ValidationException::forField('state', 'A link is confirmed or revoked.');
        }

        if (!IdentityLinkState::permits($link->state, $state)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ConflictException(sprintf('A link cannot move from %s to %s.', $link->state, $state));
        }

        $values = ['state' => $state];

        if ($state === IdentityLinkState::ACTIVE) {
            $this->access->requireSiteManager($actor);
            $userId = $input['user_id'] ?? $link->userId;
            if (!\is_int($userId) || $userId < 1 || $this->users->findById($userId) === null) {
                throw ValidationException::forField('user_id', 'Select an existing account.');
            }
            $values['user_id'] = $userId;
        }

        $this->store->updateLink($scope, $link->id, $values);
        $this->record($scope, MembershipEvent::GOVERNANCE_CHANGED, [
            'identity_link_id' => $link->id,
            'state' => $state,
            'previous_state' => $link->state,
            'issuer' => $link->issuer,
        ]);

        $resolved = $this->store->link($scope, $link->id);

        return $resolved === null ? $link->toArray() : $resolved->toArray();
    }

    /**
     * The bound a connection's rules are evaluated inside, resolved fresh.
     *
     * It is public because the evaluator's caller needs it and because a bound
     * built anywhere else would be a second answer to the question this one
     * answers. The roles it carries are the ones a mapping rule may name at
     * all, which is a registry question, and the units are this
     * organisation's own, which is a tenancy question, and both are asked here
     * so no caller has to remember to ask either.
     */
    public function boundFor(OrgScope $scope, OrgConnection $connection): MappingBound
    {
        $units = $this->store->unitIds($scope);
        $roles = [];

        foreach ($this->roles->all() as $role) {
            if ($this->roles->availableFor($role->key(), $scope->organisationId) && $role->grantableByMappingRule()) {
                $roles[$role->key()] = $role->capabilities();
            }
        }

        return MappingBound::forConnection($connection->organisationId, $units, $roles);
    }

    // ── Internals ────────────────────────────────────────────────────────

    private function require(OrgScope $scope, int $connectionId): OrgConnection
    {
        return $this->store->connection($scope, $connectionId)
            ?? throw new EntityNotFoundException('Connection not found.');
    }

    /**
     * @param array<string, mixed> $details
     */
    private function record(OrgScope $scope, string $key, array $details): void
    {
        $organisation = $this->organisations->organisation($scope);
        $this->organisations->event(
            $scope,
            (int) ($organisation['revision'] ?? 1),
            $key,
            null,
            $details,
            MembershipEventContext::manual(),
        );
    }

    /**
     * The rules, validated and bounded at save.
     *
     * A ref that names something outside this organisation is refused here
     * with the same code the catalogue uses for the same mistake, and the
     * resolved id is stored beside the ref so a later rename cannot re-point
     * the rule.
     */
    private function rulesFor(OrgScope $scope, mixed $input): MappingRuleSet
    {
        if ($input === null) {
            return MappingRuleSet::empty();
        }

        if (!\is_array($input)) {
            throw ValidationException::forField('mapping_rules', 'The rules are a list.');
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = [];

        foreach (array_values($input) as $row) {
            if (!\is_array($row)) {
                throw ValidationException::forField('mapping_rules', 'Every rule is an object.');
            }

            $rows[] = $row;
        }

        $rules = MappingRuleSet::of($rows);
        $units = $this->store->unitIds($scope);
        $bounded = [];

        foreach ($rules->rules as $index => $rule) {
            $this->guardTarget($rule, $index, $scope, $units);
            $bounded[] = $rule->resolvedTo($scope->organisationId, $rule->orgUnitId)->toStored();
        }

        return MappingRuleSet::of($bounded);
    }

    /**
     * @param list<int> $units
     */
    private function guardTarget(MappingRule $rule, int $index, OrgScope $scope, array $units): void
    {
        $field = 'mapping_rules.' . $index . '.then';

        if ($rule->orgId !== null && $rule->orgId !== $scope->organisationId) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field . '.org_id', 'forbidden_scope');
        }

        if ($rule->orgUnitId !== null && !\in_array($rule->orgUnitId, $units, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field . '.org_unit_id', 'forbidden_scope');
        }

        if (!$this->roles->availableFor($rule->roleKey, $scope->organisationId)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field . '.org_role', 'No such organisation role.');
        }

        $role = $this->roles->get($rule->roleKey);

        if (!$role->grantableByMappingRule()) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field . '.org_role', 'forbidden_scope');
        }

        if (\in_array(Capability::ADMIN, $role->capabilities(), true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field . '.org_role', 'forbidden_scope');
        }

        // The subset rule, applied to the authoring principal rather than to
        // the connection. A person may write a rule granting a role they hold
        // themselves, or any role at all if they hold the right that grants
        // roles; anything else would let an administrator hand out more than
        // they were given, through a rule instead of through the console.
        if (!$this->mayGrant($scope, $rule->roleKey)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field . '.org_role', 'forbidden_scope');
        }
    }

    /**
     * Whether the caller may write a rule granting this role.
     *
     * Answered from the scope's own resolved roles and the registry, so it
     * asks the host nothing and makes no second authorisation call: the scope
     * is the authorisation, already made.
     */
    /** @param list<string> $keys */
    public function holdsOwnership(array $keys): bool
    {
        return $this->roles->holdsOwnership($keys);
    }

    private function mayGrant(OrgScope $scope, string $roleKey): bool
    {
        if (\in_array($roleKey, $scope->roles, true)) {
            return true;
        }

        foreach ($scope->roles as $held) {
            if ($this->roles->permits($held, 'roles', $scope->organisationId)) {
                return true;
            }
        }

        // A site manager entering through an organisation route holds no
        // organisation role at all, and refusing them here would make the
        // whole surface unusable for the one principal who may verify a
        // connection. The scope records that fact on its own flag, which is
        // read rather than the basis word, because the class name may not be
        // named outside its four minting witnesses.
        return $scope->siteManager;
    }

    private function issuer(mixed $value, OrgScope $scope): string
    {
        $issuer = \is_string($value) ? trim($value) : '';

        if ($issuer === '' || \strlen($issuer) > 190) {
            throw ValidationException::forField('issuer', 'An issuer is required and is at most 190 characters.');
        }

        $this->refuseAnotherOrganisationsLocalIssuer($issuer, $scope);

        return $issuer;
    }

    /**
     * The reserved local issuer namespace, which is minted per organisation and
     * belongs to the organisation it names.
     *
     * Nothing checked this, and the local issuer is a string an organisation
     * administrator types. So one organisation could claim `local:host:{another
     * organisation's id}`, a site manager with no signal that the string
     * belonged to somebody else could verify it, and the global unique index on
     * verified_issuer_key then locked the real owner out of its own host-group
     * connection permanently, refused with "Another organisation already holds
     * that issuer verified." {@see HostGroupIssuer} names that exact failure as
     * the reason the issuer is minted per organisation in the first place.
     */
    private function refuseAnotherOrganisationsLocalIssuer(string $issuer, OrgScope $scope): void
    {
        $owner = HostGroupIssuer::organisationOf($issuer);

        if ($owner === null || $owner === $scope->organisationId) {
            return;
        }

        throw ValidationException::forField(
            'issuer',
            'That is the reserved local issuer of another organisation on this site. A local issuer is minted'
                . ' per organisation and names the one it belongs to.'
        );
    }

    /**
     * The dialect configuration bag, validated as a bag and never interpreted.
     *
     * The core does not know what any key in it means: it is handed to whatever
     * mapper reads the connection dialect, which is the only thing that does.
     * What is checked here is that it is an object of scalars and lists, so a
     * stored value cannot carry a structure a mapper would have to defend
     * against.
     *
     * @return array<string, mixed>
     */
    private function overrides(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (!\is_array($value) || array_is_list($value)) {
            throw ValidationException::forField('attribute_overrides', 'The overrides are an object of settings.');
        }

        foreach (array_keys($value) as $key) {
            if (!\is_string($key) || trim($key) === '' || \strlen($key) > 64) {
                throw ValidationException::forField(
                    'attribute_overrides',
                    'Every override is named by a string of at most 64 characters.',
                );
            }
        }

        if (\strlen(json_encode($value, JSON_THROW_ON_ERROR)) > 4000) {
            throw ValidationException::forField('attribute_overrides', 'The overrides are at most 4000 bytes.');
        }

        return $value;
    }

    private function dialect(mixed $value): string
    {
        $dialect = \is_string($value) ? trim($value) : '';

        if ($dialect === '') {
            throw ValidationException::forField('dialect', 'A connection names the dialect that reads it.');
        }

        if (!$this->mappers->has($dialect)) {
            // Fail closed on the identity path. A connection naming a dialect
            // nothing on this site can read would be a row that looks
            // configured and reads nothing, which is the shape an operator
            // spends a day debugging.
            throw ValidationException::forField('dialect', 'No installed provider reads that dialect.');
        }

        return $dialect;
    }

    /**
     * The connection kind, checked against what this site can actually read.
     *
     * `ConnectionProtocol::FREE` publishes the rule in its own docblock: "A
     * connection naming any other protocol is refused at save unless a
     * registered identity mapper declares that dialect." The constant had no
     * reader, so a stock free build accepted, verified and reported as driving
     * provisioning a connection claiming SAML, OIDC or SCIM, none of which
     * anything on the site terminates. The screen then showed a configured
     * federation nobody could sign in through, which is the exact confusion the
     * dialect refusal a few lines below exists to prevent.
     *
     * The dialect is the evidence, not the protocol word: a paid package that
     * terminates SAML registers a mapper for its dialect, and that registration
     * is what makes the word writable here.
     */
    private function protocol(mixed $value, string $dialect): string
    {
        $protocol = \is_string($value) ? trim($value) : ConnectionProtocol::HOST;

        if (\in_array($protocol, ConnectionProtocol::FREE, true)) {
            return $protocol;
        }

        // The word is checked for shape and then for a declarer, and no longer
        // against a closed core constant. The four constants are the core's own
        // documented words and were also the whole storable vocabulary, so a
        // package terminating CAS or WS-Fed could not record a connection at
        // all and its only alternative was to store "host", which would have
        // called a federation the host's own account. The declaration is still
        // the permission: an undeclared word is refused exactly as one of the
        // four undeclared words is.
        if (!ConnectionProtocol::isStorable($protocol)) {
            throw ValidationException::forField('protocol', 'Unknown connection kind.');
        }

        $mapper = $this->mappers->has($dialect) ? $this->mappers->get($dialect) : null;

        if (!$mapper instanceof TerminatesProtocol || !\in_array($protocol, $mapper->protocols(), true)) {
            throw ValidationException::forField(
                'protocol',
                'No installed provider terminates that kind of connection, so recording one would be a'
                    . ' federation nobody on this site can sign in through.'
            );
        }

        return $protocol;
    }

    /**
     * A reference, never a secret.
     *
     * The two accepted prefixes are the host's own reference grammar. A value
     * carrying neither is refused rather than stored, because the one thing
     * this column must never hold is the thing it is a reference to.
     */
    private function secretRef(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $ref = \is_string($value) ? trim($value) : '';

        if ($ref === '') {
            return null;
        }

        if (!str_starts_with($ref, 'env:') && !str_starts_with($ref, 'store:')) {
            throw ValidationException::forField(
                'secret_ref',
                'A secret reference starts with "env:" or "store:". This column never holds a secret.'
            );
        }

        if (\strlen($ref) > 190) {
            throw ValidationException::forField('secret_ref', 'A secret reference is at most 190 characters.');
        }

        return $ref;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(OrgConnection $connection): array
    {
        $shape = $connection->toArray();
        // Fail closed and say so: a connection whose dialect nothing reads
        // drives nothing, and the screen lists it as unresolved rather than as
        // configured.
        $shape['dialect_resolved'] = $this->mappers->has($connection->dialect);

        return $shape;
    }

    /**
     * The key a caller would look an identity up by, published so a screen
     * showing one row does not compose it a second way.
     */
    public static function identityKeyOf(string $issuer, string $subject): string
    {
        return IdentityKey::of($issuer, $subject);
    }
}
