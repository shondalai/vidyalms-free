<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation;

use Shondalai\VidyaLMS\Core\Application\Organisation\MembershipEventContext;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/** Every child read/write takes a resolved scope and repeats its tenant predicate. */
final class OrganisationStore
{
    /**
     * The shape org_membership_events.details is written in, stated on the row
     * rather than inside the JSON because this ledger is append only and a
     * reader has to know which rows it can understand without decoding every
     * one of them. StoredShape::UNVERSIONED is what a row written before the
     * column existed carries, and it means exactly that.
     */
    public const PAYLOAD_VERSION = 1;

    public function __construct(public readonly DatabasePort $database, private readonly ClockPort $clock)
    {
    }

    public function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    /**
     * The same instant as {@see now()}, unformatted.
     *
     * The organisation policy contexts carry a clock reading rather than a
     * string, on the same terms {@see OrganisationLearningStore::instant()}
     * states: a policy comparing dates should not be parsing the storage
     * format to do it, and it is this store's clock and not a second one, so a
     * frozen test clock freezes both.
     */
    public function instant(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /** Only the capability/scoping resolver may use this unscoped bootstrap lookup.
     * @return array<string, mixed>|null
     */
    public function findForAccess(int $id): ?array
    {
        $row = $this->database->selectOne(Query::from('organisations')->where('id', '=', $id));

        return $row === null ? null : self::numbers($row);
    }

    /** @return list<string> */
    public function activeRoles(int $organisationId, int $userId): array
    {
        $rows = $this->database->selectAll(Query::from('org_memberships', 'm')
            ->select(['r.role_key'])->join('org_role_assignments', 'r', 'm.id', 'r.membership_id', [
                ['m.organisation_id', 'r.organisation_id'],
            ])->where('m.organisation_id', '=', $organisationId)->where('m.user_id', '=', $userId)
            ->where('m.state', '=', 'active')->where('r.scope_type', '=', 'organisation')
            ->where('r.scope_id', '=', $organisationId));

        return array_values(array_unique(array_map(static fn (array $row): string => (string) $row['role_key'], $rows)));
    }

    /** @return array{unit_id: int, roles: list<string>}|null */
    public function activeUnitRoles(int $organisationId, int $userId): ?array
    {
        $rows = $this->database->selectAll(Query::from('org_memberships', 'm')->select(['r.role_key', 'r.scope_id'])
            ->join('org_role_assignments', 'r', 'm.id', 'r.membership_id', [['m.organisation_id', 'r.organisation_id']])
            ->join('org_units', 'u', 'r.scope_id', 'u.id', [['r.organisation_id', 'u.organisation_id']])
            ->where('m.organisation_id', '=', $organisationId)->where('m.user_id', '=', $userId)->where('m.state', '=', 'active')
            ->where('r.scope_type', '=', 'unit')->where('u.state', '=', 'active')->orderBy('r.id')->limit(2));
        // This editor supports one explicit role scope. Ambiguous imported
        // assignments fail closed rather than quietly unioning their reach.
        if (\count($rows) !== 1) {
            return null;
        }

        return ['unit_id' => (int) $rows[0]['scope_id'], 'roles' => [(string) $rows[0]['role_key']]];
    }

    /**
     * Every unit one unit-scoped assignment reaches, itself included, sorted.
     *
     * It is resolved when the scope is minted rather than only at query time.
     * The design's carried unit list is "sorted, deduplicated, already
     * includes descendants where the reach said so", and OrgScope was carrying
     * the anchor alone: a unit moved between a schedule's save and its run
     * changed the reach, produced a byte-identical digest, and the artefact
     * invalidation the digest exists for did not happen. It also gave the two
     * mechanisms two answers, because a consumer honouring
     * VisibilityScope::ids() saw the anchor while the closure join saw the
     * subtree.
     *
     * @return list<int>
     */
    public function unitSubtree(int $organisationId, int $unitId): array
    {
        $rows = $this->database->selectAll(Query::from('org_unit_closure')->select(['descendant_id'])
            ->where('organisation_id', '=', $organisationId)->where('ancestor_id', '=', $unitId));
        $ids = array_map(static fn (array $row): int => (int) $row['descendant_id'], $rows);
        $ids[] = $unitId;
        $unique = array_values(array_unique($ids));
        sort($unique);

        return $unique;
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int} */
    public function sitePage(int $page, int $perPage, string $search, string $state): array
    {
        $query = Query::from('organisations');

        if ($search !== '') {
            $query->whereContains('name', $search);
        }
        if ($state !== '') {
            $query->where('state', '=', $state);
        }

        return $this->page($query, $page, $perPage);
    }

    /** @return array<string, mixed> */
    public function organisation(OrgScope $scope): array
    {
        return $this->findForAccess($scope->organisationId) ?? throw new EntityNotFoundException('No organisation here.');
    }

    /** @param array<string, mixed> $values */
    public function create(array $values): int
    {
        try {
            $id = $this->insert('organisations', $values + ['root_id' => 0, 'revision' => 1]);
        } catch (\Throwable $failure) {
            if ($this->database->selectOne(Query::from('organisations')->where('slug', '=', $values['slug'])) !== null) {
                throw new ConflictException('An organisation already uses this slug.');
            }

            throw $failure;
        }
        $this->database->update('organisations', ['root_id' => $id], ['id' => $id]);

        return $id;
    }

    /** Own membership discovery is not an administrator scope or a global directory.
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function ownOrganisations(int $userId, int $page, int $perPage): array
    {
        $query = Query::from('org_memberships', 'm')->select([
            'o.id', 'o.name', 'o.slug', 'o.state', 'm.id AS membership_id',
        ])->join('organisations', 'o', 'm.organisation_id', 'o.id')
            ->where('m.user_id', '=', $userId)->where('m.state', '=', 'active')->where('o.state', '=', 'active');
        $this->membershipWindow($query);
        $total = $this->database->count(clone $query);
        $rows = $this->database->selectAll($query->orderBy('o.id')->limit($perPage)->offset(($page - 1) * $perPage));
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['membership_id'] = (int) $row['membership_id'];
        }

        return ['items' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** Personal discovery only: never a grant of organization administration.
     * @return array<string, mixed>|null
     */
    public function ownMembership(int $organisationId, int $userId): ?array
    {
        return $this->database->selectOne($this->membershipWindow(
            Query::from('org_memberships', 'm')->select(['m.*', 'o.name AS organisation_name'])
                ->join('organisations', 'o', 'm.organisation_id', 'o.id')
                ->where('m.organisation_id', '=', $organisationId)->where('m.user_id', '=', $userId)
                ->where('m.state', '=', 'active')->where('o.state', '=', 'active')
        ));
    }

    private function membershipWindow(Query $query): Query
    {
        return $query->whereAnyOf(Predicate::isNull('m.begins_at'), Predicate::compare('m.begins_at', '<=', $this->now()))
            ->whereAnyOf(Predicate::isNull('m.ends_at'), Predicate::compare('m.ends_at', '>', $this->now()));
    }

    /** Claim the root before child changes; stale requests cannot authorise guessed future revisions.
     * @param array<string, mixed> $values
     */
    public function claim(OrgScope $scope, int $expectedRevision, array $values = []): int
    {
        $observed = $this->organisation($scope);
        if ((int) $observed['revision'] !== $expectedRevision) {
            throw new ConflictException('The organisation changed. Reload before saving.', ['reason' => 'revision_conflict']);
        }
        $changed = $this->database->update('organisations', $values + [
            'revision' => $expectedRevision + 1, 'updated_at' => $this->now(),
        ], ['id' => $scope->organisationId, 'revision' => $expectedRevision]);
        if ($changed !== 1) {
            throw new ConflictException('The organisation changed. Reload before saving.', ['reason' => 'revision_conflict']);
        }

        return $expectedRevision + 1;
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int} */
    public function members(OrgScope $scope, int $page, int $perPage, string $state = ''): array
    {
        $query = Query::from('org_memberships', 'm')->select(['m.*'])->where('m.organisation_id', '=', $scope->organisationId);
        $scope->constrainUnit($query, 'm.org_unit_id');
        if ($state !== '') {
            $query->where('m.state', '=', $state);
        }
        $result = $this->page($query, $page, $perPage, 'm.id');
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $result['items']);
        $assignments = [];
        if ($ids !== []) {
            foreach (
                $this->database->selectAll(Query::from('org_role_assignments')
                ->where('organisation_id', '=', $scope->organisationId)->whereIn('membership_id', $ids)
                ->orderBy('role_key')) as $row
            ) {
                $assignments[(int) $row['membership_id']][] = $row;
            }
        }
        foreach ($result['items'] as &$item) {
            $held = $assignments[(int) $item['id']] ?? [];
            $item['role_keys'] = self::roleKeysOf($held);
            $item['role_scopes'] = self::roleScopesOf($held);
            $item['role_unit_id'] = self::roleUnitOf($held);
            $item['scope_ambiguous'] = self::scopeAmbiguousIn($held);
        }

        return $result;
    }

    /**
     * The role keys of a set of assignments.
     *
     * @param list<array<string, mixed>> $assignments
     *
     * @return list<string>
     */
    private static function roleKeysOf(array $assignments): array
    {
        return array_map(static fn (array $role): string => (string) $role['role_key'], $assignments);
    }

    /**
     * Every role this membership holds with the scope it holds it at.
     *
     * The list is the honest shape and the single unit id below is derived
     * from it. A member holding two unit-scoped roles used to be two different
     * answers depending on which read asked: the list read let the
     * alphabetically last assignment win, and the detail read returned null
     * for anybody with more than one. Both now read this.
     *
     * @param list<array<string, mixed>> $assignments
     *
     * @return list<array{role_key: string, scope_type: string, unit_id: int|null}>
     */
    private static function roleScopesOf(array $assignments): array
    {
        $scopes = [];

        foreach ($assignments as $assignment) {
            $unitScoped = (string) $assignment['scope_type'] === 'unit';
            $scopes[] = [
                'role_key' => (string) $assignment['role_key'],
                'scope_type' => (string) $assignment['scope_type'],
                'unit_id' => $unitScoped ? (int) $assignment['scope_id'] : null,
            ];
        }

        return $scopes;
    }

    /**
     * The one unit this membership's roles are scoped to, or null.
     *
     * Null covers three honestly different cases and says so rather than
     * picking a winner: no unit-scoped role at all, a mixture of
     * organisation-wide and unit-scoped roles, and two roles scoped to
     * different units. Anything else would be a lossy field that two reads
     * could disagree about, which is exactly what it was.
     *
     * @param list<array<string, mixed>> $assignments
     */
    private static function roleUnitOf(array $assignments): ?int
    {
        $units = [];

        foreach (self::roleScopesOf($assignments) as $scope) {
            if ($scope['unit_id'] === null) {
                return null;
            }

            $units[$scope['unit_id']] = true;
        }

        return \count($units) === 1 ? (int) array_key_first($units) : null;
    }

    /**
     * Whether this membership's unit reach is ambiguous, which means it has
     * none.
     *
     * {@see activeUnitRoles()} supports one explicit role scope and fails
     * closed on more than one rather than quietly unioning their reach. That
     * direction is right and its consequence is invisible: a package that
     * registers a second unit-assignable role, or an importer that writes one,
     * revokes an existing unit administrator's whole console with nothing
     * anywhere saying why. The rows are already on the read, so naming the
     * ambiguity costs nothing and is the difference between a console that can
     * explain a refusal and one that cannot.
     *
     * @param list<array<string, mixed>> $assignments
     */
    private static function scopeAmbiguousIn(array $assignments): bool
    {
        $unitScoped = 0;

        foreach (self::roleScopesOf($assignments) as $scope) {
            if ($scope['unit_id'] !== null) {
                $unitScoped++;
            }
        }

        // The same predicate activeUnitRoles() applies: more than one
        // unit-scoped assignment on one membership is what fails closed there,
        // whether or not the two name the same unit.
        return $unitScoped > 1;
    }

    /** @return array<string, mixed> */
    public function member(OrgScope $scope, int $id): array
    {
        $query = Query::from('org_memberships', 'm')->select(['m.*'])->where('m.id', '=', $id)
            ->where('m.organisation_id', '=', $scope->organisationId);
        $scope->constrainUnit($query, 'm.org_unit_id');
        $row = $this->database->selectOne($query);
        if ($row === null) {
            throw new EntityNotFoundException('No member here.');
        }
        $row = self::numbers($row);
        $assignments = $this->database->selectAll(Query::from('org_role_assignments')
                ->where('organisation_id', '=', $scope->organisationId)->where('membership_id', '=', $id)
            ->orderBy('role_key'));
        $row['role_keys'] = self::roleKeysOf($assignments);
        $row['role_scopes'] = self::roleScopesOf($assignments);
        $row['role_unit_id'] = self::roleUnitOf($assignments);
        $row['scope_ambiguous'] = self::scopeAmbiguousIn($assignments);

        return $row;
    }

    public function activeMemberId(OrgScope $scope, int $userId): ?int
    {
        $query = Query::from('org_memberships', 'm')->select(['m.id'])
            ->where('m.organisation_id', '=', $scope->organisationId)->where('m.user_id', '=', $userId)->where('m.state', '=', 'active');
        $scope->constrainUnit($query, 'm.org_unit_id');
        $row = $this->database->selectOne($query);

        return $row === null ? null : (int) $row['id'];
    }

    public function addMember(OrgScope $scope, int $userId, string $roleKey, string $source, ?string $sourceRef, ?int $unitId = null): int
    {
        $id = $this->insert('org_memberships', [
            'organisation_id' => $scope->organisationId, 'user_id' => $userId, 'org_unit_id' => $unitId,
            'state' => 'active', 'active_key' => $scope->organisationId . ':' . $userId,
            'joined_at' => $this->now(), 'left_at' => null, 'provisioned_by' => $source, 'source_ref' => $sourceRef,
            'directory_managed' => 0,
        ]);
        $this->setRole($scope, $id, $roleKey);

        return $id;
    }

    /**
     * The directory attributes of one membership, already validated.
     *
     * It takes the columns rather than a request body because the validation,
     * including the manager check that needs this same store, belongs to the
     * use case: a repository that validated would be a second place the rule
     * lives, and the importer and every provisioning source have to run the
     * same one.
     *
     * An empty map is a caller who said nothing about the directory, which is
     * not the same as a caller who cleared every field, so it writes nothing
     * at all rather than an UPDATE with no columns.
     *
     * @param array<string, mixed> $columns
     */
    public function setDirectory(OrgScope $scope, int $memberId, array $columns): void
    {
        if ($columns === []) {
            return;
        }

        $this->database->update(
            'org_memberships',
            $columns + ['updated_at' => $this->now()],
            ['organisation_id' => $scope->organisationId, 'id' => $memberId]
        );

        if ((int) ($columns['is_primary'] ?? 0) === 1) {
            $this->clearOtherPrimaries($scope, $memberId);
        }
    }

    /**
     * Leaves exactly one primary membership for this person here.
     *
     * The schema comment states the OneRoster rule and says no index enforces
     * it because a person may hold none, which left it enforced by nothing at
     * all: the at-most-one-active-membership key bounds the active rows, so
     * the reachable violation is a closed membership and an active one both
     * claiming to be primary, which a date-ranged report reads as two.
     *
     * It runs inside the caller's own transaction, in the same statement pair
     * as the write that claimed it, so the rule can never be true between two
     * commits.
     */
    private function clearOtherPrimaries(OrgScope $scope, int $memberId): void
    {
        $member = $this->database->selectOne(
            OrgScopedReader::from($scope, 'org_memberships')->select(['user_id'])->where('id', '=', $memberId)
        );

        if ($member === null) {
            return;
        }

        $others = $this->database->selectAll(
            OrgScopedReader::from($scope, 'org_memberships')->select(['id'])
                ->where('user_id', '=', (int) $member['user_id'])
                ->where('is_primary', '=', 1)
                ->where('id', '!=', $memberId)
        );

        foreach ($others as $row) {
            $this->database->update(
                'org_memberships',
                ['is_primary' => 0, 'updated_at' => $this->now()],
                ['organisation_id' => $scope->organisationId, 'id' => (int) $row['id']]
            );
        }
    }

    /**
     * Assigns one role, with the payload that role's own paramsSchema()
     * describes.
     *
     * The payload arrives already validated and already StoredShape wrapped,
     * because validating a provider's schema needs the registry and a store
     * holds none. Absent, the column stays null, which is what a role with a
     * closed empty schema stores and what all four free roles have.
     *
     * @param array<string, mixed>|null $params the wrapped body, or null for a payload-free role
     */
    public function setRole(
        OrgScope $scope,
        int $memberId,
        string $roleKey,
        ?int $unitId = null,
        ?array $params = null,
        int $paramsVersion = 1,
    ): void {
        $this->member($scope, $memberId);
        $this->database->delete('org_role_assignments', [
            'organisation_id' => $scope->organisationId, 'membership_id' => $memberId,
        ]);
        $this->insert('org_role_assignments', [
            'organisation_id' => $scope->organisationId, 'membership_id' => $memberId,
            'role_key' => $roleKey, 'scope_type' => $unitId === null ? 'organisation' : 'unit',
            'scope_id' => $unitId ?? $scope->organisationId,
            'assigned_by' => $scope->actorId,
            'params' => $params === null ? null : json_encode($params, JSON_THROW_ON_ERROR),
            'params_version' => max(1, $paramsVersion),
        ]);
    }

    /**
     * The payload one member's assignment of one role carries, unwrapped.
     *
     * Read here rather than folded into member(), because the member read is
     * the console's list row and a payload is a detail one screen asks for.
     *
     * @return array{params: array<string, mixed>, params_version: int}
     */
    public function roleParams(OrgScope $scope, int $memberId, string $roleKey): array
    {
        $row = $this->database->selectOne(
            OrgScopedReader::from($scope, 'org_role_assignments')
                ->where('membership_id', '=', $memberId)
                ->where('role_key', '=', $roleKey)
        );

        if ($row === null || !\is_string($row['params'] ?? null) || $row['params'] === '') {
            return ['params' => [], 'params_version' => 1];
        }

        /** @var array<string, mixed> $stored */
        $stored = json_decode((string) $row['params'], true, 32, JSON_THROW_ON_ERROR);

        return [
            'params' => StoredShape::bodyOf($stored),
            'params_version' => max(1, (int) ($row['params_version'] ?? 1)),
        ];
    }

    public function setMemberState(OrgScope $scope, int $memberId, int $userId, string $state): void
    {
        $this->database->update('org_memberships', [
            'state' => $state, 'active_key' => $state === 'active' ? $scope->organisationId . ':' . $userId : null,
            'left_at' => $state === 'left' ? $this->now() : null, 'updated_at' => $this->now(),
        ], ['id' => $memberId, 'organisation_id' => $scope->organisationId]);
    }

    /**
     * The distinct role keys this organisation's active memberships hold.
     *
     * The console needs it to name a role whose provider is gone: an
     * unregistered key resolves to no capabilities and the membership row
     * survives, which is the fail-closed direction, but a row nobody can see
     * is a person whose access silently stopped working with no explanation on
     * the screen.
     *
     * @return list<string>
     */
    public function heldRoleKeys(OrgScope $scope): array
    {
        $query = Query::from('org_memberships', 'm')->select(['r.role_key'])
            ->join('org_role_assignments', 'r', 'm.id', 'r.membership_id', [
                ['m.organisation_id', 'r.organisation_id'],
            ])->where('m.organisation_id', '=', $scope->organisationId)->where('m.state', '=', 'active');
        $scope->constrainUnit($query, 'm.org_unit_id');
        $keys = [];

        foreach ($this->database->selectAll($query) as $row) {
            $keys[(string) $row['role_key']] = true;
        }

        $found = array_keys($keys);
        sort($found);

        return $found;
    }

    /**
     * How many active organisation-wide owners this organisation still has.
     *
     * The keys are passed in rather than spelled here, because which roles own
     * an organisation is the registry's answer: a package that registers its
     * own ownership role inherits the last-owner invariant instead of finding
     * the store counting a key it has never heard of. No ownership role
     * registered at all counts nobody, which is honest and unreachable, since
     * the caller only asks when somebody already holds one.
     *
     * @param list<string> $ownershipKeys
     */
    public function activeOwnerCount(OrgScope $scope, array $ownershipKeys): int
    {
        if ($ownershipKeys === []) {
            return 0;
        }

        return $this->database->count(Query::from('org_memberships', 'm')->select(['m.id'])
            ->join('org_role_assignments', 'r', 'm.id', 'r.membership_id', [
                ['m.organisation_id', 'r.organisation_id'],
            ])->where('m.organisation_id', '=', $scope->organisationId)->where('m.state', '=', 'active')
            ->whereIn('r.role_key', $ownershipKeys)->where('r.scope_type', '=', 'organisation')
            ->where('r.scope_id', '=', $scope->organisationId));
    }

    /**
     * Appends one row to the membership event stream.
     *
     * The context is optional and trailing, which is the whole of the migration
     * shape: every caller written before R5 keeps compiling and keeps writing
     * exactly the row it wrote, and a caller that knows its provenance says so.
     * A null context is recorded as a console write by a principal, because
     * that is what every one of those callers is; it is not recorded as
     * unknown, which would put a second meaning on a column that already has
     * one.
     *
     * Both clocks are stamped for every row written from here on, so a null in
     * either column means one thing only: the row predates them.
     *
     * @param array<string, mixed> $details
     */
    public function event(
        OrgScope $scope,
        int $revision,
        string $key,
        ?int $subjectId,
        array $details = [],
        ?MembershipEventContext $context = null,
    ): void {
        $now = $this->now();

        $this->insert('org_membership_events', [
            'organisation_id' => $scope->organisationId, 'actor_id' => $scope->actorId,
            'subject_id' => $subjectId, 'event_key' => $key, 'organisation_revision' => $revision,
            'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'payload_version' => self::PAYLOAD_VERSION,
        ] + ($context ?? MembershipEventContext::manual())->columns($now));
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int} */
    /**
     * The membership history, narrowed to the caller's own reach.
     *
     * **The unit predicate was missing and the column was written for it.**
     * org_membership_events.org_unit_id ships with a comment saying it exists
     * "so a unit manager's own audit read is an index range rather than a scan
     * that decodes every payload looking for a unit id inside JSON", and this
     * read never used it: a unit-scoped caller with the events right got the
     * whole tenant's history. No free role could reach that combination,
     * because the free reach literal excludes the events right, so nothing was
     * leaking; the first role to declare a wider reach through ReachesUnits
     * would have been the leak, which is exactly when a missing predicate is
     * found the expensive way.
     *
     * A row that names no unit is organisation-wide and is therefore outside a
     * subtree read, which is what the join says rather than what a null check
     * would have to say twice.
     *
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function events(OrgScope $scope, int $page, int $perPage): array
    {
        // Aliased and qualified, because the unit narrowing joins the closure
        // table and both sides carry an organisation_id.
        $query = Query::from('org_membership_events', 'e')->select(['e.*'])
            ->where('e.organisation_id', '=', $scope->organisationId);
        $scope->constrainUnit($query, 'e.org_unit_id');
        $result = $this->page($query, $page, $perPage, 'e.id');
        foreach ($result['items'] as &$row) {
            $row['details'] = json_decode((string) $row['details'], true, 32, JSON_THROW_ON_ERROR);
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function insert(string $table, array $row): int
    {
        return $this->database->insert($table, $row + ['created_at' => $this->now(), 'updated_at' => $this->now()]);
    }

    /** @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int} */
    private function page(Query $query, int $page, int $perPage, string $order = 'id'): array
    {
        $total = $this->database->count(clone $query);
        $items = $this->database->selectAll($query->orderBy($order)->limit($perPage)->offset(($page - 1) * $perPage));

        return ['items' => array_map(self::numbers(...), $items), 'total' => $total, 'page' => $page, 'per_page' => $perPage];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function numbers(array $row): array
    {
        foreach (
            ['id', 'organisation_id', 'actor_id', 'subject_id', 'organisation_revision', 'revision',
            'root_id', 'depth', 'created_by', 'user_id', 'org_unit_id', 'directory_managed',
            'payload_version', 'is_primary', 'manager_user_id'] as $key
        ) {
            if (isset($row[$key])) {
                $row[$key] = (int) $row[$key];
            }
        }
        unset($row['active_key']);

        return $row;
    }
}
