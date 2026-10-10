<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class ManageOrganisationUnits
{
    /**
     * The setting that raises the unit tree's depth bound.
     *
     * The bound was the literal six, which meant a district with a deeper tree
     * needed a migration to load its own roster. It is a settings row because
     * the design says the bound is raised by configuration rather than by
     * migration, and because the alternative is a column on a table that has
     * no business knowing.
     */
    public const SETTING_MAX_DEPTH = 'organisations.unit_depth_max';

    /** What the bound is when no site has said otherwise. */
    public const DEFAULT_MAX_DEPTH = 6;

    public function __construct(
        private readonly OrgScopeResolver $access,
        private readonly OrganisationStore $store,
        /**
         * Defaulted, so a caller built before the setting existed keeps the
         * shipped bound rather than losing the bound altogether. A null here
         * means "nobody can tell me otherwise", which is exactly six.
         */
        private readonly ?Settings $settings = null,
    ) {
    }

    /**
     * How deep a unit tree may go on this site.
     *
     * Clamped rather than trusted: a stored zero or a negative would make
     * every unit refuse, and a stored value beyond the path column's own
     * capacity would move the failure from a named error at row one to a
     * truncated path nobody notices.
     */
    public function maxDepth(): int
    {
        $configured = $this->settings?->getInt(self::SETTING_MAX_DEPTH, self::DEFAULT_MAX_DEPTH)
            ?? self::DEFAULT_MAX_DEPTH;

        return max(1, min(64, $configured));
    }

    /** @return array<string, mixed> */
    public function get(Principal $actor, int $orgId, int $unitId): array
    {
        return self::find($this->store, $this->access->require($actor, $orgId), $unitId);
    }

    /** @return array<string, mixed> */
    public function list(Principal $actor, int $orgId, int $page = 1, int $perPage = 25, string $search = ''): array
    {
        $scope = $this->access->require($actor, $orgId);
        OrganisationInput::page($page, $perPage);
        if (mb_strlen($search) > 200) {
            throw ValidationException::forField('search', 'Search text exceeds 200 characters.');
        }
        $query = OrgScopedReader::from($scope, 'org_units', 'u')->select(['u.*']);
        $scope->constrainUnit($query, 'u.id');
        if ($search !== '') {
            $query->whereContains('u.path', $search);
        }
        $total = $this->store->database->count(clone $query);
        $rows = $this->store->database->selectAll($query->orderBy('u.path')->orderBy('u.id')
            ->limit($perPage)->offset(($page - 1) * $perPage));

        return ['items' => array_map(self::present(...), $rows), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(Principal $actor, int $orgId, ?int $unitId, array $input): array
    {
        OrganisationInput::only($input, ['expected_revision', 'name', 'parent_id', 'state']);
        $revision = OrganisationInput::integer($input, 'expected_revision');

        return $this->store->database->transactional(function () use ($actor, $orgId, $unitId, $input, $revision): array {
            $scope = $this->access->require($actor, $orgId, 'people');
            if ($scope->unitId !== null) {
                throw new \Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException(
                    'Unit structure is managed by organisation administrators.'
                );
            }
            $old = $unitId === null ? null : self::find($this->store, $scope, $unitId);
            $name = isset($input['name']) ? OrganisationInput::text($input, 'name', 190) : (string) ($old['name'] ?? '');
            if ($name === '' || str_contains($name, '/')) {
                throw ValidationException::forField('name', 'Enter a unit name without slashes.');
            }
            $parentId = array_key_exists('parent_id', $input) ? $input['parent_id'] : ($old['parent_id'] ?? null);
            if ($parentId !== null && (!\is_int($parentId) || $parentId < 1)) {
                throw ValidationException::forField('parent_id', 'Select a unit in this organisation or no parent.');
            }
            if ($unitId !== null && $parentId === $unitId) {
                throw ValidationException::forField('parent_id', 'A unit cannot contain itself.');
            }
            $parent = $parentId === null ? null : self::find($this->store, $scope, $parentId);
            if ($parent !== null && $parent['state'] !== 'active') {
                throw new ConflictException('An inactive unit cannot receive children.');
            }
            $depth = (int) ($parent['depth'] ?? 0) + 1;
            $path = ($parent === null ? '' : (string) $parent['path'] . '/') . $name;
            $maxDepth = $this->maxDepth();
            if ($depth > $maxDepth || mb_strlen($path) > 1024) {
                throw ValidationException::forField('parent_id', sprintf(
                    'Unit trees support %d levels and paths up to 1024 characters.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $maxDepth,
                ));
            }
            $state = $input['state'] ?? $old['state'] ?? 'active';
            if (!\in_array($state, ['active', 'inactive'], true)) {
                throw ValidationException::forField('state', 'Expected active or inactive.');
            }
            $next = $this->store->claim($scope, $revision);
            if ($old !== null && ($path !== $old['path'] || $state !== 'active')) {
                // Tree rewrites never silently retarget a subtree or move its
                // members. Move leaf units; reorganise children explicitly.
                $children = $this->store->database->count(Query::from('org_units')
                    ->where('organisation_id', '=', $orgId)->where('parent_id', '=', $unitId));
                if ($children > 0) {
                    throw new ConflictException('Move or update child units before changing this branch.');
                }
            }
            if ($unitId !== null && $state === 'inactive') {
                $members = $this->store->database->count(Query::from('org_memberships')
                    ->where('organisation_id', '=', $orgId)->where('org_unit_id', '=', $unitId)->where('state', '=', 'active'));
                if ($members > 0) {
                    throw new ConflictException('Move active members before making their unit inactive.');
                }
            }
            $digest = hash('sha256', $orgId . ':' . $path);
            $duplicate = $this->store->database->selectOne(Query::from('org_units')
                ->where('organisation_id', '=', $orgId)->where('path_digest', '=', $digest));
            if ($duplicate !== null && (int) $duplicate['id'] !== $unitId) {
                throw new ConflictException('A unit already uses this path.');
            }
            $row = ['organisation_id' => $orgId, 'parent_id' => $parentId, 'name' => $name, 'path' => $path,
                'path_digest' => $digest, 'depth' => $depth, 'state' => $state, 'updated_at' => $this->store->now()];
            if ($unitId === null) {
                $unitId = $this->store->database->insert('org_units', $row + ['created_at' => $this->store->now()]);
            } else {
                $this->store->database->update('org_units', $row, ['organisation_id' => $orgId, 'id' => $unitId]);
                $this->store->database->delete('org_unit_closure', ['organisation_id' => $orgId, 'descendant_id' => $unitId]);
            }
            // The bound is the live setting and not the literal six the depth
            // check used to share with it. Raising the setting left this at
            // six, so from depth eight every unit recorded only seven
            // ancestors, the root stopped being one of them, and a unit
            // administrator anchored at the top of the tree silently could not
            // see the people at the bottom of it.
            $ancestors = $parentId === null ? [] : $this->store->database->selectAll(Query::from('org_unit_closure')
                ->where('organisation_id', '=', $orgId)->where('descendant_id', '=', $parentId)
                ->orderBy('depth')->limit($maxDepth + 1));
            $ancestors[] = ['ancestor_id' => $unitId, 'depth' => -1];
            foreach ($ancestors as $ancestor) {
                if ((int) $ancestor['ancestor_id'] === $unitId && (int) $ancestor['depth'] !== -1) {
                    throw new ConflictException('This parent would create a unit cycle.');
                }
                $this->store->database->insert('org_unit_closure', ['organisation_id' => $orgId,
                    'ancestor_id' => (int) $ancestor['ancestor_id'], 'descendant_id' => $unitId,
                    'depth' => (int) $ancestor['depth'] + 1, 'created_at' => $this->store->now(), 'updated_at' => $this->store->now()]);
            }
            $this->store->event($scope, $next, 'unit.updated', null, ['unit_id' => $unitId, 'path' => $path, 'state' => $state]);

            return ['organisation_revision' => $next, 'item' => self::present(['id' => $unitId] + $row)];
        });
    }

    /** @return array<string, mixed> */
    public static function find(OrganisationStore $store, OrgScope $scope, int $unitId): array
    {
        $query = OrgScopedReader::from($scope, 'org_units', 'u')->select(['u.*'])->where('u.id', '=', $unitId);
        $scope->constrainUnit($query, 'u.id');
        $row = $store->database->selectOne($query) ?? throw new EntityNotFoundException('No organisation unit here.');

        return self::present($row);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function present(array $row): array
    {
        foreach (['id', 'organisation_id', 'parent_id', 'depth'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        unset($row['path_digest']);

        return $row;
    }
}
