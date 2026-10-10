<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Provider\Notification\Audience\LineManagerAudience;
use Shondalai\VidyaLMS\Core\Provider\Notification\Audience\OrgAdminsAudience;
use Shondalai\VidyaLMS\Core\Provider\Notification\Audience\OrgUnitManagersAudience;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Registry\Context\AudienceContext;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility as ContextFiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\HonoursOrgScope;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverInterface;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The three free escalation audiences.
 *
 * The rule every one of them exists to keep is the one asserted first and
 * last: an organisation audience resolves from the organisation's own rows,
 * and with no organisation scope it resolves to nobody rather than falling
 * back to a site-wide answer.
 */
final class OrganisationAudiencesTest extends TestCase
{
    private TestPorts $env;
    private OrganisationStore $store;
    private OrgRoleRegistry $roles;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->roles = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $this->roles->register($role);
        }

        foreach (range(1, 12) as $id) {
            $this->env->users->add($id, 'Synthetic User ' . $id, 'user' . $id . '@example.test');
        }

        $this->env->authorization->grant(1, Capability::MANAGE);
        $this->store = new OrganisationStore($this->env->database, $this->env->clock);
    }

    public function testAllThreeDeclareBothMarkers(): void
    {
        foreach ([$this->admins(), $this->unitManagers(), new LineManagerAudience()] as $audience) {
            self::assertInstanceOf(AudienceResolverInterface::class, $audience);
            self::assertInstanceOf(HonoursOrgScope::class, $audience);
            self::assertInstanceOf(ContextFiltersByVisibility::class, $audience);
        }
    }

    public function testOrgAdminsResolvesTheOrganisationsOwnAdministratorsAndNobodyElses(): void
    {
        $scope = $this->seedTwoOrganisations();

        $context = AudienceContext::create(
            $this->env->database,
            $this->env->authorization,
            orgScope: $scope,
        );

        // Organisation 1: the owner (2) and the administrator (3) both hold a
        // role permitting "people". The reporter (4) and the plain member (5)
        // do not, and organisation 2's owner (7) is nobody's business here.
        self::assertSame([2, 3], iterator_to_array($this->admins()->resolve($context), false));
    }

    public function testAnAudienceWithNoOrganisationScopeResolvesToNobody(): void
    {
        $this->seedTwoOrganisations();
        $context = AudienceContext::create($this->env->database, $this->env->authorization, learnerUserId: 5);

        self::assertSame([], iterator_to_array($this->admins()->resolve($context), false));
        self::assertSame([], iterator_to_array($this->unitManagers()->resolve($context), false));
        self::assertSame([], iterator_to_array((new LineManagerAudience())->resolve($context), false));
    }

    /**
     * A registered audience that needed a tenant and was handed none is not a
     * missing audience. Reporting it as one told an administrator that the
     * provider was gone when it was installed, resolved correctly, and
     * answered nobody because the fan-out that asked had no organisation.
     */
    public function testAnUnscopedOrganisationAudienceIsReportedAsUnscopedAndNotAsMissing(): void
    {
        $this->seedTwoOrganisations();
        $unscoped = AudienceContext::create($this->env->database, $this->env->authorization, learnerUserId: 5);

        self::assertSame(
            \Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverRegistry::UNSCOPED_REASON,
            \Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverRegistry::emptyReasonFor(
                $this->admins(),
                $unscoped,
            ),
        );
        self::assertSame(
            'audience_unscoped',
            \Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverRegistry::UNSCOPED_REASON
        );

        // A site-wide resolver that answers nobody really is a miss, and the
        // two reasons must stay apart.
        $siteWide = new class implements AudienceResolverInterface {
            public function key(): string
            {
                return 'acme.nobody';
            }

            public function label(): string
            {
                return 'acme.audience.nobody';
            }

            public function resolve(AudienceContext $context): iterable
            {
                return [];
            }
        };
        self::assertSame(
            \Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverRegistry::MISS_REASON,
            \Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverRegistry::emptyReasonFor(
                $siteWide,
                $unscoped,
            ),
        );
    }

    /**
     * The refusal AudienceContext, ReportContext, FiltersByVisibility and
     * AuthorizationPort all published in prose and nothing implemented: inside
     * an organisational run, a resolver declaring only the older marker is
     * skipped rather than trusted to ignore the scope politely.
     */
    public function testAResolverDeclaringOnlyTheOlderMarkerIsRefusedInsideAnOrganisationalRun(): void
    {
        $scope = $this->seedTwoOrganisations();
        $organisational = AudienceContext::create(
            $this->env->database,
            $this->env->authorization,
            orgScope: $scope,
        );
        self::assertTrue($organisational->visibilityScope?->isOrganisational());

        $older = new class implements AudienceResolverInterface, ContextFiltersByVisibility {
            public function key(): string
            {
                return 'acme.legacy';
            }

            public function label(): string
            {
                return 'acme.audience.legacy';
            }

            public function resolve(AudienceContext $context): iterable
            {
                yield 999;
            }
        };

        self::assertSame(
            \Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverRegistry::SCOPE_UNSUPPORTED_REASON,
            \Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverRegistry::refusalFor(
                $older,
                $organisational,
            ),
        );

        // The three free ones declare both markers, so none of them is caught
        // by the refusal that exists for providers written before this.
        foreach ([$this->admins(), $this->unitManagers(), new LineManagerAudience()] as $free) {
            self::assertNull(
                \Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverRegistry::refusalFor(
                    $free,
                    $organisational,
                ),
            );
        }

        // And a site-wide run never asks the question at all.
        self::assertNull(
            \Shondalai\VidyaLMS\Core\Registry\Notification\Audience\AudienceResolverRegistry::refusalFor(
                $older,
                AudienceContext::create($this->env->database, $this->env->authorization),
            ),
        );
    }

    public function testACourseScopedRunResolvesToNobodyRatherThanTheWholeRoster(): void
    {
        $scope = $this->seedTwoOrganisations();
        $context = AudienceContext::create(
            $this->env->database,
            $this->env->authorization,
            visibilityScope: VisibilityScope::forCourse(9),
            orgScope: $scope,
        );

        self::assertSame([], iterator_to_array($this->admins()->resolve($context), false));
    }

    public function testUnitManagersResolvesUnitScopedAssignmentsAndNotOrganisationScopedOnes(): void
    {
        $scope = $this->seedTwoOrganisations();
        $unitId = $this->seedUnit($scope);

        // User 6 administers one unit; users 2 and 3 administer the whole
        // organisation and are a different audience.
        $memberId = $this->store->addMember($scope, 6, 'org_member', 'manual', null, $unitId);
        $this->store->setRole($scope, $memberId, 'org_admin', $unitId);

        $context = AudienceContext::create($this->env->database, $this->env->authorization, orgScope: $scope);

        self::assertSame([6], iterator_to_array($this->unitManagers()->resolve($context), false));
        self::assertSame([2, 3], iterator_to_array($this->admins()->resolve($context), false));
    }

    public function testLineManagerResolvesTheLearnersOwnManagerInsideTheOrganisation(): void
    {
        $scope = $this->seedTwoOrganisations();
        $this->setManager($scope, learner: 5, manager: 3);

        $context = AudienceContext::create(
            $this->env->database,
            $this->env->authorization,
            learnerUserId: 5,
            orgScope: $scope,
        );

        self::assertSame([3], iterator_to_array((new LineManagerAudience())->resolve($context), false));
    }

    public function testAManagerWhoseOwnMembershipHasClosedIsDropped(): void
    {
        $scope = $this->seedTwoOrganisations();
        $this->setManager($scope, learner: 5, manager: 3);

        // The manager leaves. The learner's row still names them, which is
        // exactly the disagreement the second guard exists for: the departed
        // manager must stop receiving this organisation's reminders.
        $managerMembership = $this->store->activeMemberId($scope, 3);
        self::assertNotNull($managerMembership);
        $this->store->setMemberState($scope, $managerMembership, 3, 'left');

        $context = AudienceContext::create(
            $this->env->database,
            $this->env->authorization,
            learnerUserId: 5,
            orgScope: $scope,
        );

        self::assertSame([], iterator_to_array((new LineManagerAudience())->resolve($context), false));
    }

    public function testARegisteredThirdPartyRoleJoinsTheAdministratorAudienceWithNoCoreEdit(): void
    {
        $scope = $this->seedTwoOrganisations();
        $this->roles->register(new class implements \Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleInterface {
            public function key(): string
            {
                return 'acme.regional_lead';
            }

            public function label(): string
            {
                return 'acme.role.regional_lead';
            }

            /** @return list<string> */
            public function capabilities(): array
            {
                return [Capability::MANAGE_GROUPS];
            }

            /** @return list<string> */
            public function actions(): array
            {
                return ['people', 'read'];
            }

            public function permits(string $action): bool
            {
                return \in_array($action, $this->actions(), true);
            }

            public function grantableByMappingRule(): bool
            {
                return true;
            }

            /** @return array<string, mixed> */
            public function paramsSchema(): array
            {
                return ['type' => 'object', 'properties' => new \stdClass()];
            }

            public function currentShape(): int
            {
                return 1;
            }

            public function group(): string
            {
                return 'acme';
            }

            public function order(): int
            {
                return 10;
            }
        });

        $memberId = $this->store->addMember($scope, 8, 'org_member', 'manual', null);
        $this->store->setRole($scope, $memberId, 'acme.regional_lead');

        $context = AudienceContext::create($this->env->database, $this->env->authorization, orgScope: $scope);

        self::assertSame([2, 3, 8], iterator_to_array($this->admins()->resolve($context), false));
    }

    private function admins(): OrgAdminsAudience
    {
        return new OrgAdminsAudience($this->roles);
    }

    private function unitManagers(): OrgUnitManagersAudience
    {
        return new OrgUnitManagersAudience($this->roles);
    }

    private function setManager(OrgScope $scope, int $learner, int $manager): void
    {
        $membershipId = $this->store->activeMemberId($scope, $learner);
        self::assertNotNull($membershipId);
        $this->env->database->update(
            'org_memberships',
            ['manager_user_id' => $manager],
            ['id' => $membershipId, 'organisation_id' => $scope->organisationId]
        );
    }

    private function seedUnit(OrgScope $scope): int
    {
        return $this->env->database->insert('org_units', [
            'organisation_id' => $scope->organisationId,
            'parent_id' => null,
            'name' => 'Engineering',
            'path' => 'Engineering',
            'path_digest' => hash('sha256', $scope->organisationId . ':Engineering'),
            'depth' => 1,
            'state' => 'active',
            'created_at' => $this->store->now(),
            'updated_at' => $this->store->now(),
        ]);
    }

    /** Two organisations, so every "and nobody else's" assertion has somebody else to exclude. */
    private function seedTwoOrganisations(): OrgScope
    {
        $resolver = new OrgScopeResolver(new AccessGuard($this->env->authorization), $this->store, $this->roles);
        $first = null;

        foreach ([1 => [2, 3, 4, 5], 2 => [7, 9, 10, 11]] as $index => [$owner, $admin, $reporter, $member]) {
            $created = $this->store->create([
                'name' => 'Organisation ' . $index,
                'slug' => 'org-' . $index,
                'created_by' => 1,
            ]);
            $scope = OrgScope::fromResolver($resolver, Principal::user(1), $created, 'people');
            $this->store->addMember($scope, $owner, 'org_owner', 'manual', null);
            $this->store->addMember($scope, $admin, 'org_admin', 'manual', null);
            $this->store->addMember($scope, $reporter, 'org_reporter', 'manual', null);
            $this->store->addMember($scope, $member, 'org_member', 'manual', null);
            $first ??= $scope;
        }

        self::assertInstanceOf(OrgScope::class, $first);

        return $first;
    }
}
