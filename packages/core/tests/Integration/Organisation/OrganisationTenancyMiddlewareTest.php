<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisations;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationRoutes;
use Shondalai\VidyaLMS\Core\Application\Platform\DatabaseIdempotencyStore;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\Middleware\ActiveOrganisation;
use Shondalai\VidyaLMS\Core\Http\Middleware\OrganisationAccessLogMiddleware;
use Shondalai\VidyaLMS\Core\Http\Middleware\OrgScopeMiddleware;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\AdminAccessLogStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseIdempotencyKeyRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgType\OrgTypeRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The tenancy middleware and the access-log writer, over the real kernel.
 *
 * Two rules are asserted through a booted ApiKernel rather than by calling
 * the middleware directly, because both of them are claims about what happens
 * on a request: that the active organisation is pinned once, and that no
 * organisation-scoped read reaches a handler without leaving a row.
 */
final class OrganisationTenancyMiddlewareTest extends TestCase
{
    private TestPorts $env;
    private ApiKernel $api;
    private ActiveOrganisation $active;
    private AdminAccessLogStore $log;
    private Settings $settings;
    private OrganisationStore $store;
    private OrgRoleRegistry $roles;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();

        foreach ([1, 2, 3, 4, 5, 6] as $id) {
            $this->env->users->add($id, 'Synthetic User ' . $id, 'user' . $id . '@example.test');
        }

        $this->env->authorization->grant(1, Capability::MANAGE);
        $this->env->authorization->grant(2, Capability::MANAGE_GROUPS);
        $this->env->authorization->grant(3, Capability::MANAGE_GROUPS);

        $roles = self::roleRegistry();
        $this->roles = $roles;
        $this->store = new OrganisationStore($this->env->database, $this->env->clock);
        $this->active = new ActiveOrganisation();
        // Wired exactly as the kernel wires it: the resolver publishes every
        // scope it mints onto the request, so the log records the resolution
        // the route made rather than a second one made with the default right.
        $access = new OrgScopeResolver(
            new AccessGuard($this->env->authorization),
            $this->store,
            $roles,
            $this->active,
        );
        $this->settings = new Settings(new DatabaseSettingsRepository($this->env->database, $this->env->clock));
        $this->log = new AdminAccessLogStore($this->env->database, $this->env->clock);
        $this->api = new ApiKernel(new NullLogger());

        OrganisationRoutes::register(
            $this->api,
            new ManageOrganisations($access, $this->store, $this->env->users, $roles, self::typeRegistry()),
            new ManageOrganisationMembers(
                new OrganisationAccess($access),
                $this->store,
                $roles,
                $this->env->users,
                $this->env->translator,
                null,
                $this->active,
            ),
            $access,
            new IdempotentExecution(new DatabaseIdempotencyStore(
                new DatabaseIdempotencyKeyRepository($this->env->database, $this->env->clock),
                $this->env->clock,
            )),
            $this->env->database
        );

        $this->api->use(new OrgScopeMiddleware(
            $this->active,
            $access,
            $this->store,
            $this->settings,
            $this->env->random
        ));
        $this->api->use(new OrganisationAccessLogMiddleware($this->active, $this->log, new NullLogger()));

        $this->seed();
    }

    public function testAnOrganisationScopedReadLeavesOneRowCarryingBothAnchorsAndTheScopeDigest(): void
    {
        $response = $this->get('/manage/organisations/1/members', 2);
        self::assertSame(200, $response);

        $rows = $this->env->database->selectAll(Query::from('admin_access_log')->orderBy('id'));
        self::assertCount(1, $rows);
        self::assertSame('org.members.read', $rows[0]['event_type']);
        self::assertSame('/manage/organisations/1/members', $rows[0]['route']);
        self::assertSame('allowed', $rows[0]['outcome']);
        self::assertSame(1, (int) $rows[0]['actor_org_id']);
        self::assertSame(1, (int) $rows[0]['subject_org_id']);
        self::assertSame('org:1', $rows[0]['resource']);
        self::assertSame(2, (int) $rows[0]['actor_id']);
        self::assertSame('org_admin', $rows[0]['actor_role_key']);
        // Two members were disclosed: the owner and the delegated administrator.
        self::assertSame(2, (int) $rows[0]['record_count']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $rows[0]['scope_digest']);
        self::assertNotSame('', (string) $rows[0]['request_ref']);
        self::assertSame(AdminAccessLogStore::GENESIS, $rows[0]['prev_hash']);
    }

    /**
     * Route::match trims the outer slashes off before comparing, so an extra
     * leading slash served the identical rows through a path that neither the
     * tenancy middleware nor the log covered. Both shipped host mounts happen
     * to normalise, which is why this was reachable only through the core
     * kernel, which is the boundary a third host adapter is written against.
     */
    public function testAnExtraLeadingSlashServesTheSameRowsAndIsCoveredIdentically(): void
    {
        self::assertSame(200, $this->get('/manage/organisations/1/members', 2));
        self::assertSame(200, $this->get('//manage/organisations/1/members', 2));

        $rows = $this->env->database->selectAll(Query::from('admin_access_log')->orderBy('id'));
        self::assertCount(2, $rows, 'a path Route::match accepts is a path the log must cover');
        self::assertSame(['allowed', 'allowed'], array_column($rows, 'outcome'));
        self::assertSame([2, 2], array_map(static fn (array $r): int => (int) $r['record_count'], $rows));
        self::assertSame(
            ['/manage/organisations/1/members', '/manage/organisations/1/members'],
            array_column($rows, 'route')
        );
        self::assertSame(ApiKernel::canonicalPath('//manage/organisations/1/'), '/manage/organisations/1');
    }

    /**
     * The middleware above dispatch can only ask for the "read" right, and a
     * route resolves the right it needs. A role permitting "events" and not
     * "read" therefore served the event history with a 200 and was recorded
     * as a refusal that disclosed nothing, which understates a real
     * disclosure in the record that exists to show it.
     */
    public function testAReadUnderARightOtherThanReadIsRecordedAsAllowedWithItsScope(): void
    {
        $this->roles->register(new \Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrgRole(
            'acme.event_auditor',
            ['events'],
            80,
            true,
        ));
        $siteScope = $this->siteScope(1);
        $memberId = $this->store->activeMemberId($siteScope, 2);
        self::assertNotNull($memberId);
        $this->store->setRole($siteScope, $memberId, 'acme.event_auditor');
        $this->env->database->delete('admin_access_log', []);

        self::assertSame(200, $this->get('/manage/organisations/1/events', 2));

        $row = $this->env->database->selectOne(Query::from('admin_access_log')->orderBy('id', Query::SORT_DESC));
        self::assertIsArray($row);
        self::assertSame('allowed', $row['outcome']);
        self::assertSame(1, (int) $row['actor_org_id']);
        self::assertSame(1, (int) $row['subject_org_id']);
        self::assertSame('acme.event_auditor', $row['actor_role_key']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row['scope_digest']);
    }

    /**
     * The subject anchor, its shipped index and AdminAccessLogQuery::forSubject
     * were all over a column no route ever wrote: the middleware matched a
     * `/users/{id}` path shape and no organisation route has that segment.
     */
    public function testTheMemberReadRecordsThePersonItWasAboutAndTheSubjectQueryFindsIt(): void
    {
        $siteScope = $this->siteScope(1);
        $memberId = $this->store->activeMemberId($siteScope, 5);
        self::assertNotNull($memberId);

        self::assertSame(200, $this->get('/manage/organisations/1/members/' . $memberId, 2));

        $row = $this->env->database->selectOne(Query::from('admin_access_log')->orderBy('id', Query::SORT_DESC));
        self::assertIsArray($row);
        self::assertSame('org.members.read', $row['event_type']);
        self::assertSame(5, (int) $row['subject_user_id']);
        self::assertSame(1, (int) $row['record_count']);

        $matched = $this->log->page(
            \Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\AdminAccessLogQuery::forSubject($siteScope, 5)
        );
        self::assertSame(1, $matched['total']);

        // The list read names nobody, so it must not be attributed to one.
        self::assertSame(200, $this->get('/manage/organisations/1/members', 2));
        $listed = $this->env->database->selectOne(Query::from('admin_access_log')->orderBy('id', Query::SORT_DESC));
        self::assertIsArray($listed);
        self::assertNull($listed['subject_user_id']);
    }

    /**
     * A guest never reaches an organisation route, so their row carries actor
     * 0, no address hash and no request reference. It is a row with no audit
     * value that anybody can create at will, in a table with no delete use
     * case, so the attempt is recorded and then collapsed.
     */
    public function testAnonymousProbesCannotAppendAuditRows(): void
    {
        foreach (range(1, 5) as $ignored) {
            self::assertSame(401, $this->get('/manage/organisations/1/members', 0));
        }

        $rows = $this->env->database->selectAll(Query::from('admin_access_log')->orderBy('id'));
        self::assertCount(0, $rows);

        // A different organisation and a different surface are different
        // events, and an authenticated read is never collapsed.
        self::assertSame(401, $this->get('/manage/organisations/2/members', 0));
        self::assertSame(401, $this->get('/manage/organisations/1/events', 0));
        self::assertSame(200, $this->get('/manage/organisations/1/members', 2));
        self::assertSame(200, $this->get('/manage/organisations/1/members', 2));
        self::assertSame(2, $this->env->database->count(Query::from('admin_access_log')));
    }

    public function testEncodedIdsHaveTheSameScopeAndAuditRecord(): void
    {
        self::assertSame(200, $this->get('/manage/organisations/%31/members', 2));
        $row = $this->env->database->selectOne(Query::from('admin_access_log'));
        self::assertNotNull($row);
        self::assertSame(1, (int) $row['subject_org_id']);
        self::assertSame('org.members.read', $row['event_type']);
        foreach (['1%0A', '1%2Fmembers', '999999999999999999999999'] as $id) {
            self::assertSame(404, $this->get('/manage/organisations/' . $id . '/members', 2));
        }
        self::assertSame(1, $this->env->database->count(Query::from('admin_access_log')));
    }

    public function testRefusalsAreBoundedAndUnknownOrganisationsAndRoutesAreNotLogged(): void
    {
        foreach (['members', 'events', 'members', 'events'] as $tail) {
            self::assertSame(404, $this->get('/manage/organisations/1/' . $tail, 3));
        }
        self::assertSame(404, $this->get('/manage/organisations/999/members', 3));
        self::assertSame(404, $this->get('/manage/organisations/1/not-a-route', 3));
        self::assertSame(1, $this->env->database->count(Query::from('admin_access_log')));
    }

    public function testTheSiteManagerReadThatBypassesTheRolePredicateIsRecordedToo(): void
    {
        // User 1 holds vidyalms.manage and no organisation role at all, which
        // is the path that skipped the tenant predicate with no compensating
        // record before this wave.
        self::assertSame([], $this->store->activeRoles(1, 1));
        self::assertSame(200, $this->get('/manage/organisations/1/members', 1));

        $row = $this->env->database->selectOne(Query::from('admin_access_log'));
        self::assertIsArray($row);
        self::assertSame(1, (int) $row['actor_id']);
        self::assertSame(1, (int) $row['actor_org_id']);
        self::assertSame(1, (int) $row['subject_org_id']);
        self::assertSame('allowed', $row['outcome']);
        self::assertNull($row['actor_role_key']);
    }

    public function testAReadOfAnotherOrganisationIsRecordedAsARefusalWithNothingDisclosed(): void
    {
        // User 3 administers organisation 2 and has no claim at all on 1.
        self::assertSame(404, $this->get('/manage/organisations/1/members', 3));

        $row = $this->env->database->selectOne(Query::from('admin_access_log'));
        self::assertIsArray($row);
        self::assertSame('refused', $row['outcome']);
        self::assertSame(3, (int) $row['actor_id']);
        self::assertNull($row['actor_org_id']);
        self::assertSame(1, (int) $row['subject_org_id']);
        self::assertSame(0, (int) $row['record_count']);
        self::assertNull($row['scope_digest']);
    }

    public function testASiteManagerAndADelegatedAdministratorProduceTwoDifferentScopeDigests(): void
    {
        $this->get('/manage/organisations/1/members', 1);
        $this->get('/manage/organisations/1/members', 2);

        $digests = array_map(
            static fn (array $row): string => (string) $row['scope_digest'],
            $this->env->database->selectAll(Query::from('admin_access_log')->orderBy('id'))
        );

        self::assertCount(2, $digests);
        self::assertNotSame($digests[0], $digests[1]);
    }

    public function testTheActiveOrganisationIsPinnedFromThePathAndThenRemembered(): void
    {
        self::assertSame(0, $this->settings->getInt(OrgScopeMiddleware::PREFERENCE_PREFIX . '2', 0));

        $this->get('/manage/organisations/1/members', 2);
        self::assertSame(1, $this->settings->getInt(OrgScopeMiddleware::PREFERENCE_PREFIX . '2', 0));

        // The pin is released when the request ends, so nothing leaks into the
        // next caller in the same process.
        self::assertNull($this->active->scope());
    }

    public function testTheRememberedPreferenceAnswersARouteThatNamesNoOrganisation(): void
    {
        $this->settings->set(OrgScopeMiddleware::PREFERENCE_PREFIX . '2', 1);
        $seen = $this->pinFor('/manage/organisations', 2);

        self::assertInstanceOf(OrgScope::class, $seen[0]);
        self::assertSame(1, $seen[0]->organisationId);
        self::assertSame(ActiveOrganisation::SOURCE_REMEMBERED, $seen[1]);
    }

    public function testASoleActiveMembershipAnswersWhenNothingIsRemembered(): void
    {
        $seen = $this->pinFor('/me/organisations', 2);

        self::assertInstanceOf(OrgScope::class, $seen[0]);
        self::assertSame(1, $seen[0]->organisationId);
        self::assertSame(ActiveOrganisation::SOURCE_SOLE_MEMBERSHIP, $seen[1]);
    }

    public function testARememberedOrganisationTheCallerCanNoLongerReachIsNotPinned(): void
    {
        // User 4 is in nothing at all, and a stale preference is not authority.
        $this->settings->set(OrgScopeMiddleware::PREFERENCE_PREFIX . '4', 1);
        $seen = $this->pinFor('/manage/organisations', 4);

        self::assertNull($seen[0]);
        self::assertSame(ActiveOrganisation::SOURCE_NONE, $seen[1]);
    }

    public function testAWriteAndANonOrganisationReadLeaveNoRow(): void
    {
        $this->api->handle(new ApiRequest('GET', '/me/organisations', Principal::user(2)));
        $this->api->handle(new ApiRequest('GET', '/manage/organisations', Principal::user(1)));

        self::assertSame(0, $this->env->database->count(Query::from('admin_access_log')));
    }

    /**
     * An audit record that could five-hundred a working read would be an
     * availability defect wearing a compliance badge, so a log that cannot be
     * written is reported through the application logger and the response is
     * returned. The chain then shows a gap rather than a forgery.
     */
    public function testALogThatCannotBeWrittenDoesNotTakeTheResponseWithIt(): void
    {
        $broken = new ApiKernel(new NullLogger());
        $roles = self::roleRegistry();
        $access = new OrgScopeResolver(new AccessGuard($this->env->authorization), $this->store, $roles);
        OrganisationRoutes::register(
            $broken,
            new ManageOrganisations($access, $this->store, $this->env->users, $roles, self::typeRegistry()),
            new ManageOrganisationMembers(
                new OrganisationAccess($access),
                $this->store,
                $roles,
                $this->env->users,
                $this->env->translator,
            ),
            $access,
            new IdempotentExecution(new DatabaseIdempotencyStore(
                new DatabaseIdempotencyKeyRepository($this->env->database, $this->env->clock),
                $this->env->clock,
            )),
            $this->env->database
        );

        $active = new ActiveOrganisation();
        $broken->use(new OrgScopeMiddleware($active, $access, $this->store, $this->settings, $this->env->random));
        $broken->use(new OrganisationAccessLogMiddleware(
            $active,
            // A store pointed at a table this database does not have, which is
            // what a half-applied migration looks like from inside a request.
            new AdminAccessLogStore(new class ($this->env->database) implements DatabasePort
            {
                public function __construct(private readonly DatabasePort $inner)
                {
                }

                /** @return list<array<string, mixed>> */
                public function selectAll(Query $query): array
                {
                    return $this->inner->selectAll($query);
                }

                /** @return array<string, mixed>|null */
                public function selectOne(Query $query): ?array
                {
                    return $this->inner->selectOne($query);
                }

                public function count(Query $query): int
                {
                    return $this->inner->count($query);
                }

                /** @param array<string, mixed> $row */
                public function insert(string $table, array $row): int
                {
                    throw new \RuntimeException('no such table: admin_access_log');
                }

                /**
                 * @param array<string, mixed> $values
                 * @param array<string, mixed> $where
                 */
                public function update(string $table, array $values, array $where): int
                {
                    return $this->inner->update($table, $values, $where);
                }

                /**
                 * @param array<string, mixed> $row
                 * @param list<string>         $uniqueBy
                 * @param list<string>         $updateColumns
                 */
                public function upsert(string $table, array $row, array $uniqueBy, array $updateColumns): void
                {
                    $this->inner->upsert($table, $row, $uniqueBy, $updateColumns);
                }

                /** @param array<string, mixed> $where */
                public function delete(string $table, array $where): int
                {
                    return $this->inner->delete($table, $where);
                }

                public function transactional(callable $operation): mixed
                {
                    return $this->inner->transactional($operation);
                }
            }, $this->env->clock),
            new NullLogger(),
        ));

        $response = $broken->handle(new ApiRequest('GET', '/manage/organisations/1/members', Principal::user(2)));

        self::assertSame(200, $response->status);
        self::assertSame(0, $this->env->database->count(Query::from('admin_access_log')));
    }

    /**
     * What the tenancy pair costs a console screen, measured rather than
     * assumed, because the plan's own risk note asks for a number.
     *
     * The pair adds a bounded and constant number of reads per request. It is
     * pinned so that a later change which made the log walk the chain, or made
     * the resolver page a roster, shows up here as a number rather than as a
     * slow screen on somebody's site.
     */
    public function testTheTenancyPairAddsABoundedConstantCostPerRequest(): void
    {
        $counting = new \Shondalai\VidyaLMS\Core\Tests\Support\CountingDatabase($this->env->database);
        $bare = new ApiKernel(new NullLogger());
        $withPair = new ApiKernel(new NullLogger());
        $roles = self::roleRegistry();
        $access = new OrgScopeResolver(new AccessGuard($this->env->authorization), $this->store, $roles);

        foreach ([$bare, $withPair] as $kernel) {
            OrganisationRoutes::register(
                $kernel,
                new ManageOrganisations($access, $this->store, $this->env->users, $roles, self::typeRegistry()),
                new ManageOrganisationMembers(
                    new OrganisationAccess($access),
                    $this->store,
                    $roles,
                    $this->env->users,
                    $this->env->translator,
                ),
                $access,
                new IdempotentExecution(new DatabaseIdempotencyStore(
                    new DatabaseIdempotencyKeyRepository($this->env->database, $this->env->clock),
                    $this->env->clock,
                )),
                $this->env->database
            );
        }

        $active = new ActiveOrganisation();
        $withPair->use(new OrgScopeMiddleware($active, $access, $this->store, $this->settings, $this->env->random));
        $withPair->use(new OrganisationAccessLogMiddleware(
            $active,
            new AdminAccessLogStore($counting, $this->env->clock),
            new NullLogger(),
        ));

        $request = new ApiRequest('GET', '/manage/organisations/1/members', Principal::user(2));

        $counting->reset();
        self::assertSame(200, $bare->handle($request)->status);
        $without = $counting->reads;

        $counting->reset();
        self::assertSame(200, $withPair->handle($request)->status);
        $with = $counting->reads;

        // One read for the chain's previous hash. The insert is a write and
        // is not counted here; the resolver's own reads run against the plain
        // database both times, so this isolates what the log itself costs.
        self::assertSame($without + 1, $with);
    }

    /**
     * Every organisation-scoped route family sits under one path shape, which
     * is what lets one middleware cover all of them instead of one edit per
     * route. The event key drops the row ids: a vocabulary with one term per
     * row is a vocabulary nothing can group by, and the ids are already on the
     * record's own columns.
     */
    public function testTheEventVocabularyNamesTheSurfaceAndNeverTheRow(): void
    {
        self::assertSame(
            'org.detail.read',
            OrganisationAccessLogMiddleware::eventType('/manage/organisations/7')
        );
        self::assertSame(
            'org.members.read',
            OrganisationAccessLogMiddleware::eventType('/manage/organisations/7/members/42')
        );
        self::assertSame(
            'org.seat-pools.ledger.read',
            OrganisationAccessLogMiddleware::eventType('/manage/organisations/7/seat-pools/3/ledger')
        );
        self::assertSame(
            'org.units.read',
            OrganisationAccessLogMiddleware::eventType('/manage/organisations/7/units/3')
        );

        // The three organisation paths the writer deliberately does not cover:
        // a site-wide listing that names no organisation, a learner reading
        // their own memberships, and the invitation ceremony, whose caller is
        // not a member of anything yet and whose authority is a token.
        foreach (['/manage/organisations', '/me/organisations', '/me/organisation-invitations/claim'] as $path) {
            self::assertNull(OrgScopeMiddleware::organisationInPath($path), $path);
        }

        self::assertSame(7, OrgScopeMiddleware::organisationInPath('/manage/organisations/7/seat-pools'));
    }

    /**
     * The pin as the handler chain saw it, captured from inside the chain
     * because the middleware releases it on the way out.
     *
     * @return array{0: ?OrgScope, 1: string}
     */
    private function pinFor(string $path, int $userId): array
    {
        $probe = new class ($this->active) implements \Shondalai\VidyaLMS\Core\Http\Middleware {
            public ?OrgScope $scope = null;

            public string $basis = ActiveOrganisation::SOURCE_NONE;

            public function __construct(private readonly ActiveOrganisation $active)
            {
            }

            public function process(ApiRequest $request, callable $next): \Shondalai\VidyaLMS\Core\Http\ApiResponse
            {
                $this->scope = $this->active->scope();
                $this->basis = $this->active->source();

                return $next($request);
            }
        };
        $this->api->use($probe);
        $this->api->handle(new ApiRequest('GET', $path, Principal::user($userId)));

        return [$probe->scope, $probe->basis];
    }

    /** The four free roles, seated the way the kernel seats them. */
    private static function roleRegistry(): OrgRoleRegistry
    {
        $registry = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $registry->register($role);
        }

        return $registry;
    }

    /** The eleven free organisation types, seated the same way. */
    private static function typeRegistry(): OrgTypeRegistry
    {
        $registry = new OrgTypeRegistry();

        foreach (CoreOrganisationProviders::types() as $type) {
            $registry->register($type);
        }

        return $registry;
    }

    private function get(string $path, int $userId): int
    {
        $this->sequence++;
        $principal = $userId < 1 ? Principal::guest() : Principal::user($userId);

        return $this->api->handle(new ApiRequest('GET', $path, $principal))->status;
    }

    /** A site-manager scope, minted through the real resolver like every other. */
    private function siteScope(int $organisationId): OrgScope
    {
        return (new OrgScopeResolver(
            new AccessGuard($this->env->authorization),
            $this->store,
            $this->roles,
        ))->require(Principal::user(1), $organisationId, 'people');
    }

    private function seed(): void
    {
        $resolver = new OrgScopeResolver(
            new AccessGuard($this->env->authorization),
            $this->store,
            self::roleRegistry()
        );

        // User 1 holds vidyalms.manage and is deliberately a member of nothing,
        // because the site-manager path this suite pins is exactly the caller
        // who reads an organisation without holding a role in it.
        foreach ([1 => [2, 5], 2 => [3, 6]] as $organisationId => [$administrator, $owner]) {
            $created = $this->store->create([
                'name' => 'Organisation ' . $organisationId,
                'slug' => 'org-' . $organisationId,
                'created_by' => 1,
            ]);
            self::assertSame($organisationId, $created);

            $scope = OrgScope::fromResolver($resolver, Principal::user(1), $organisationId, 'people');
            $this->store->addMember($scope, $owner, 'org_owner', 'manual', null);
            $this->store->addMember($scope, $administrator, 'org_admin', 'manual', null);
        }
    }
}
