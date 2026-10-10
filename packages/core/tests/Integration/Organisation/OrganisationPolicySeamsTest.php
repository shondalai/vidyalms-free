<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgPolicyResolution;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CarryEverythingUnitMove;
use Shondalai\VidyaLMS\Core\Provider\Organisation\OrgSourcedOnlyVisibility;
use Shondalai\VidyaLMS\Core\Provider\Organisation\SuspendAndRetainTermination;
use Shondalai\VidyaLMS\Core\Registry\OrgMembershipTermination\OrgMembershipTerminationRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The three organisation policy seats, which were seated and read by nothing.
 *
 * A booted free kernel counted zero providers on `orgVisibility`,
 * `orgMembershipTermination` and `orgUnitMove`, no class anywhere implemented
 * any of the three interfaces, and nothing outside the registry files read
 * them. So a Business package registering into any of the three got a silent
 * no-op while the edition table said the seam was there.
 *
 * Every case here fails on that starting point: the census cases fail because
 * the registries are empty, and the three behaviour cases fail because nothing
 * asks.
 */
final class OrganisationPolicySeamsTest extends TestCase
{
    private const MANAGER = 1;

    private const LEARNER = 3;

    private TestPorts $env;

    private CoreKernel $kernel;

    private int $organisationId;

    private int $memberId;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();

        foreach ([1, 2, 3] as $id) {
            $this->env->users->add($id, 'Person ' . $id, 'person' . $id . '@example.test');
        }

        $this->env->authorization->grant(self::MANAGER, Capability::MANAGE);
        $this->kernel = new CoreKernel($this->env->ports);
        $organisation = $this->kernel->organisations->create(
            Principal::user(self::MANAGER),
            ['name' => 'Northlight', 'slug' => 'northlight'],
        );
        $this->organisationId = (int) $organisation['id'];
        $member = $this->kernel->organisationMembers->add(
            Principal::user(self::MANAGER),
            $this->organisationId,
            ['expected_revision' => (int) $organisation['revision'], 'user_id' => self::LEARNER, 'role_key' => 'org_member'],
        );
        $this->memberId = (int) $member['item']['id'];
    }

    /** All three seats answer a free provider, which none of them did. */
    public function testTheThreeSeatsEachCarryTheFreeProviderTheDesignNames(): void
    {
        $registries = $this->kernel->registries;

        self::assertTrue($registries->orgVisibility->has(OrgSourcedOnlyVisibility::KEY));
        self::assertTrue($registries->orgMembershipTermination->has(SuspendAndRetainTermination::KEY));
        self::assertTrue($registries->orgUnitMove->has(CarryEverythingUnitMove::KEY));
        self::assertCount(1, $registries->orgVisibility->keys());
        self::assertCount(1, $registries->orgMembershipTermination->keys());
        self::assertCount(1, $registries->orgUnitMove->keys());
    }

    /**
     * The visibility seam is consulted, and a policy that widens is obeyed.
     *
     * The free answer is the organisation's own rows and nothing else, so a
     * learner's self-purchased course is invisible to their employer. A policy
     * naming `purchase` makes it visible, and only for a member of the
     * organisation that policy governs.
     */
    public function testAVisibilityPolicyThatWidensIsActuallyConsulted(): void
    {
        $this->course();
        $this->purchase(self::LEARNER);
        $this->purchase(2, member: false);

        $free = $this->kernel->organisationLearning->list(Principal::user(self::MANAGER), $this->organisationId);
        self::assertSame(0, $free['total'], 'the free build discloses a self-purchased row');

        $this->kernel->registries->orgVisibility->register(new WideningVisibilityPolicy());
        $this->kernel->settings->set(
            OrgPolicyResolution::VISIBILITY_SETTING . '.' . $this->organisationId,
            WideningVisibilityPolicy::KEY,
        );

        $widened = $this->kernel->organisationLearning->list(Principal::user(self::MANAGER), $this->organisationId);

        self::assertSame(1, $widened['total'], 'the widening policy was registered and never asked');
        self::assertSame(self::LEARNER, (int) $widened['items'][0]['user_id'], 'a non-member was disclosed');
    }

    /**
     * The closure seam is consulted, and a policy that holds access keeps it.
     *
     * The free policy releases the access the membership sourced. A policy that
     * answers no is a notice period, and the entitlement stays active.
     */
    public function testATerminationPolicyThatHoldsAccessKeepsTheEntitlement(): void
    {
        $this->course();
        $entitlementId = $this->orgGrant();

        $this->kernel->registries->orgMembershipTermination->register(new NoticePeriodTermination());
        $this->kernel->settings->set(
            OrgPolicyResolution::TERMINATION_SETTING,
            NoticePeriodTermination::KEY,
        );
        $this->kernel->organisationMembers->update(
            Principal::user(self::MANAGER),
            $this->organisationId,
            $this->memberId,
            ['expected_revision' => $this->revision(), 'state' => 'suspended'],
        );

        $row = $this->env->database->selectOne(Query::from('entitlements')->where('id', '=', $entitlementId));
        self::assertIsArray($row);
        self::assertSame('active', (string) $row['status'], 'the closure ran under a policy that said not to');

        $event = $this->env->database->selectOne(
            Query::from('org_membership_events')->orderBy('id', 'DESC'),
        );
        self::assertIsArray($event);
        self::assertStringContainsString(NoticePeriodTermination::KEY, (string) $event['details']);
    }

    /**
     * A setting naming a policy this site has not installed falls through to
     * the free one, and an empty seat resolves nothing at all.
     *
     * The fall-through is the same one field disclosure already had, and it
     * matters for the same reason: an organisation whose paid policy was
     * uninstalled gets the free behaviour rather than no behaviour. The empty
     * seat is the published fail-closed direction, which the closure applies
     * as a refusal.
     */
    public function testAnUninstalledPolicyFallsThroughAndAnEmptySeatResolvesNothing(): void
    {
        $registries = $this->kernel->registries;
        $this->kernel->settings->set(OrgPolicyResolution::TERMINATION_SETTING, 'gone-away');
        $resolution = new OrgPolicyResolution(
            $registries->orgVisibility,
            $registries->orgMembershipTermination,
            $registries->orgUnitMove,
            $this->kernel->settings,
        );

        self::assertSame(
            SuspendAndRetainTermination::KEY,
            $resolution->terminationFor($this->organisationId)?->key(),
        );

        $bare = new OrgPolicyResolution(
            $registries->orgVisibility,
            new OrgMembershipTerminationRegistry(),
            $registries->orgUnitMove,
            $this->kernel->settings,
        );

        self::assertNull($bare->terminationFor($this->organisationId), 'an empty seat must not resolve a policy');
    }

    /** And with no policy resolvable, a membership cannot be closed at all. */
    public function testAMembershipCannotBeClosedWithNoPolicyInstalled(): void
    {
        $members = new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers(
            $this->kernel->organisationAccessPolicy,
            $this->kernel->organisationStore,
            $this->kernel->registries->orgRoles,
            $this->env->ports->users,
            $this->env->ports->translator,
            null,
            null,
            new OrgPolicyResolution(
                null,
                new OrgMembershipTerminationRegistry(),
                null,
                $this->kernel->settings,
            ),
        );

        $this->expectException(ConflictException::class);
        $members->update(
            Principal::user(self::MANAGER),
            $this->organisationId,
            $this->memberId,
            ['expected_revision' => $this->revision(), 'state' => 'suspended'],
        );
    }

    /**
     * The unit-move seam is consulted, and a policy that drops the anchor drops
     * it.
     *
     * The free build repointed nothing: a unit manager moved from one unit to
     * another went on holding a role scoped to the unit they had left.
     */
    public function testAUnitMovePolicyDecidesWhetherTheUnitScopedRoleTravels(): void
    {
        [$bristol, $leeds] = $this->units();
        $this->kernel->organisationMembers->update(
            Principal::user(self::MANAGER),
            $this->organisationId,
            $this->memberId,
            [
                'expected_revision' => $this->revision(),
                'org_unit_id' => $bristol,
                'role_key' => 'org_admin',
                'role_unit_id' => $bristol,
            ],
        );

        self::assertSame($bristol, $this->roleScope(), 'the fixture did not anchor the role on a unit');

        $this->kernel->organisationMembers->update(
            Principal::user(self::MANAGER),
            $this->organisationId,
            $this->memberId,
            ['expected_revision' => $this->revision(), 'org_unit_id' => $leeds],
        );

        self::assertSame($leeds, $this->roleScope(), 'carry_everything left the role managing the unit they left');

        $this->kernel->registries->orgUnitMove->register(new DropAnchorUnitMove());
        $this->kernel->settings->set(OrgPolicyResolution::UNIT_MOVE_SETTING, DropAnchorUnitMove::KEY);
        $this->kernel->organisationMembers->update(
            Principal::user(self::MANAGER),
            $this->organisationId,
            $this->memberId,
            ['expected_revision' => $this->revision(), 'org_unit_id' => $bristol],
        );

        self::assertSame($this->organisationId, $this->roleScope(), 'a policy that drops the anchor was not asked');
    }

    private function roleScope(): int
    {
        $row = $this->env->database->selectOne(
            Query::from('org_role_assignments')->where('membership_id', '=', $this->memberId),
        );
        self::assertIsArray($row);

        return (int) $row['scope_id'];
    }

    /** @return array{0: int, 1: int} */
    private function units(): array
    {
        $bristol = $this->kernel->organisationUnits->save(
            Principal::user(self::MANAGER),
            $this->organisationId,
            null,
            ['expected_revision' => $this->revision(), 'name' => 'Bristol'],
        );
        $leeds = $this->kernel->organisationUnits->save(
            Principal::user(self::MANAGER),
            $this->organisationId,
            null,
            ['expected_revision' => $this->revision(), 'name' => 'Leeds'],
        );

        return [(int) $bristol['item']['id'], (int) $leeds['item']['id']];
    }

    private function revision(): int
    {
        $row = $this->env->database->selectOne(
            Query::from('organisations')->where('id', '=', $this->organisationId),
        );
        self::assertIsArray($row);

        return (int) $row['revision'];
    }

    private function course(): void
    {
        $this->env->database->insert('courses', [
            'title' => 'Manual Handling', 'slug' => 'manual-handling', 'state' => 'published',
            'completion_rule' => 'all_lessons', 'created_by' => self::MANAGER,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->env->database->insert('org_course_releases', [
            'organisation_id' => $this->organisationId, 'course_id' => 1,
            'released_by' => self::MANAGER, 'state' => 'active',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function purchase(int $userId, bool $member = true): void
    {
        $this->env->database->insert('entitlements', [
            'user_id' => $userId, 'course_id' => 1, 'source' => 'purchase', 'status' => 'active',
            'granted_by' => $userId,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }

    private function orgGrant(): int
    {
        return $this->env->database->insert('entitlements', [
            'user_id' => self::LEARNER, 'course_id' => 1, 'source' => 'organisation', 'status' => 'active',
            'granted_by' => self::MANAGER, 'organisation_id' => $this->organisationId,
            'org_membership_id' => $this->memberId,
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
    }
}
