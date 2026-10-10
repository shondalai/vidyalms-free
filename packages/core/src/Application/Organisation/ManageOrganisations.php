<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\InvitationMatch\InvitationMatchRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgType\OrgTypeRegistry;

final class ManageOrganisations
{
    public function __construct(
        private readonly OrgScopeResolver $access,
        private readonly OrganisationStore $store,
        private readonly UsersPort $users,
        /**
         * The role registry, for the one role this class names: whatever the
         * creator becomes.
         *
         * It is resolved from the ownership marker rather than spelled, so a
         * site that replaced the free owner role with its own gets its own
         * role on the organisations it creates.
         */
        private readonly OrgRoleRegistry $roles,
        /**
         * The type registry, read on every organisation read.
         *
         * The stored type is a label and never a gate, so an unresolved one is
         * returned as its own key with a flag saying so, rather than being
         * hidden or refusing the read. That is the published normal path, and
         * this is the free route that reaches the seam.
         */
        private readonly OrgTypeRegistry $types,
        private readonly ?OrganisationAccessTermination $termination = null,
        /**
         * The acceptance policies, for the one question saving an organisation
         * asks: is this a policy this site actually has.
         *
         * Optional and trailing so a caller built before the column still
         * constructs; absent, the field is refused rather than stored
         * unvalidated, because a key nothing can validate is a key that fails
         * closed at somebody's acceptance.
         */
        private readonly ?InvitationMatchRegistry $matches = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function list(Principal $actor, int $page = 1, int $perPage = 25, string $search = '', string $state = ''): array
    {
        $this->access->requireSiteManager($actor);
        OrganisationInput::page($page, $perPage);
        if (mb_strlen($search) > 200 || !\in_array($state, ['', 'active', 'suspended'], true)) {
            throw ValidationException::forField('filters', 'Unsupported search or state filter.');
        }

        return $this->store->sitePage($page, $perPage, $search, $state);
    }

    /** @return array<string, mixed> */
    public function get(Principal $actor, int $id): array
    {
        return $this->withType($this->store->organisation($this->access->require($actor, $id)));
    }

    /**
     * The stored type, resolved through the registry.
     *
     * Two keys rather than one, because losing the stored value would be worse
     * than showing it unlabelled: the key is always returned, the label is the
     * provider's answer, and type_unresolved says which of the two happened so
     * a screen can say "supplied by a package that is not installed" instead
     * of printing a bare string and hoping.
     *
     * @param array<string, mixed> $organisation
     *
     * @return array<string, mixed>
     */
    /**
     * The organisation type the input names, validated against the registry.
     *
     * The registry seated eleven free providers and a package could register a
     * twelfth, and no route accepted the column, so a registered type could
     * never be chosen: the read side resolved a label and the write side had
     * nowhere for a key to come from except a hand-written UPDATE.
     *
     * Absent means "leave it alone", which on a create is the column's own
     * default. There is no way to clear it, because the column is NOT NULL
     * with a default and "organisation" is a registered type: an organisation
     * with no type is not a state this schema has.
     *
     * @param array<string, mixed> $input
     */
    private function validatedType(array $input): ?string
    {
        if (!array_key_exists('type', $input)) {
            return null;
        }

        $type = $input['type'];

        if (!\is_string($type) || !$this->types->has($type)) {
            throw ValidationException::forField('type', 'Select an installed organisation type.');
        }

        return $type;
    }

    /**
     * @param array<string, mixed> $organisation
     *
     * @return array<string, mixed>
     */
    private function withType(array $organisation): array
    {
        $key = (string) ($organisation['type'] ?? '');
        $label = $key === '' ? null : $this->types->labelFor($key);

        return $organisation + [
            'type_label' => $label,
            'type_unresolved' => $key !== '' && $label === null,
            // The catalogue beside the value, so a console can offer the
            // eleven free types and any a package registered rather than
            // hard-coding a list the registry is supposed to own.
            'available_types' => $this->types->keys(),
        ];
    }

    /** @return array<string, mixed> */
    public function mine(Principal $actor, int $page = 1, int $perPage = 25): array
    {
        if ($actor->isGuest()) {
            throw new \Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException('Authentication is required.');
        }
        OrganisationInput::page($page, $perPage);

        return $this->store->ownOrganisations($actor->userId, $page, $perPage);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(Principal $actor, array $input): array
    {
        $this->access->requireSiteManager($actor);
        OrganisationInput::only($input, ['name', 'slug', 'type']);
        $name = OrganisationInput::text($input, 'name', 255);
        $slug = OrganisationInput::text($input, 'slug', 190);
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) !== 1) {
            throw ValidationException::forField('slug', 'Use lowercase letters, numbers and single hyphens.');
        }
        $type = $this->validatedType($input);
        if (!$this->users->exists($actor->userId)) {
            throw ValidationException::forField('owner', 'The creator must have an existing CMS account.');
        }

        return $this->store->database->transactional(function () use ($actor, $name, $slug, $type): array {
            $id = $this->store->create(['name' => $name, 'slug' => $slug, 'created_by' => $actor->userId]
                + ($type === null ? [] : ['type' => $type]));
            $scope = $this->access->require($actor, $id, 'settings');
            $this->store->addMember($scope, $actor->userId, $this->ownerRoleKey(), 'manual', null);
            $this->store->event($scope, 1, 'organisation.created', $actor->userId);

            return $this->withType($this->store->organisation($scope));
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(Principal $actor, int $id, array $input): array
    {
        OrganisationInput::only($input, ['expected_revision', 'name', 'state', 'type', 'invitation_match_key']);
        $revision = OrganisationInput::integer($input, 'expected_revision');
        $values = [];
        if (array_key_exists('name', $input)) {
            $values['name'] = OrganisationInput::text($input, 'name', 255);
        }
        if (array_key_exists('type', $input)) {
            $values['type'] = $this->validatedType($input);
        }
        // Which account may use an invitation addressed to an address. It is
        // the organisation's choice and had no route at all: three call sites
        // named the registry's compile-time default, so a provider could be
        // registered and never asked anything. Validated when the request
        // names it and never when it does not, on the same terms every other
        // stored provider key follows: an organisation whose policy package
        // has since been removed must still be renamable.
        if (array_key_exists('invitation_match_key', $input)) {
            $key = $input['invitation_match_key'];

            if (!\is_string($key) || $this->matches === null || !$this->matches->has($key)) {
                throw ValidationException::forField('invitation_match_key', 'Select an installed invitation match policy.');
            }

            $values[InvitationMatchRegistry::COLUMN] = $key;
        }
        if (array_key_exists('state', $input)) {
            if (!\in_array($input['state'], ['active', 'suspended'], true)) {
                throw ValidationException::forField('state', 'Expected active or suspended.');
            }
            // Lifecycle changes remain site-owned; a delegated owner cannot
            // suspend the boundary and lock out every delegated administrator.
            $this->access->requireSiteManager($actor);
            $values['state'] = $input['state'];
        }
        if ($values === []) {
            throw ValidationException::forField('input', 'Choose at least one field to change.');
        }

        return $this->store->database->transactional(function () use ($actor, $id, $revision, $values): array {
            $scope = $this->access->require($actor, $id, 'settings');
            if (($values['state'] ?? null) === 'active') {
                $this->termination?->requireSettled($scope);
            }
            $next = $this->store->claim($scope, $revision, $values);
            $cleanup = null;
            if (($values['state'] ?? null) === 'suspended') {
                $cleanup = $this->termination?->execute($scope, $next, 'org_suspended');
            }
            $this->store->event($scope, $next, 'organisation.updated', null, ['fields' => array_keys($values)]);

            return $this->withType($this->store->organisation($scope))
                + ($cleanup === null ? [] : ['access_state' => 'disabled', 'cleanup' => $cleanup]);
        });
    }

    /**
     * The role the creator is given, resolved from the ownership marker.
     *
     * A site with no ownership role registered cannot create an organisation
     * that anybody can administer, so it is refused here rather than producing
     * one nobody owns. That is the fail-closed direction the role registry
     * publishes, reached from the one path that has to name a role.
     */
    private function ownerRoleKey(): string
    {
        $keys = $this->roles->ownershipKeys();

        if ($keys === []) {
            throw new ConflictException('No organisation owner role is installed, so nobody could administer this organisation.');
        }

        return $keys[0];
    }
}
