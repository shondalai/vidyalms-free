<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers;
use Shondalai\VidyaLMS\Core\Application\Organisation\MembershipDirectory;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The seven SCIM directory attributes, the three OneRoster values, and the
 * manager check that is not optional.
 */
final class MembershipDirectoryTest extends TestCase
{
    private TestPorts $env;
    private OrganisationStore $store;
    private OrgRoleRegistry $roles;
    private OrgScopeResolver $resolver;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->roles = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $this->roles->register($role);
        }

        foreach (range(1, 8) as $id) {
            $this->env->users->add($id, 'Synthetic User ' . $id, 'user' . $id . '@example.test');
        }

        $this->env->authorization->grant(1, Capability::MANAGE);
        $this->store = new OrganisationStore($this->env->database, $this->env->clock);
        $this->resolver = new OrgScopeResolver(new AccessGuard($this->env->authorization), $this->store, $this->roles);
    }

    public function testTheSchemaCarriesAllTenColumnsAndTheManagerIndex(): void
    {
        $table = SchemaDefinition::load()->table('org_memberships');

        foreach (
            ['is_primary', 'begins_at', 'ends_at', 'job_title', 'department_label', 'division',
                'organization_label', 'cost_centre', 'employee_number', 'manager_user_id'] as $column
        ) {
            self::assertTrue($table->hasColumn($column), $column);
        }

        // Every one is nullable or defaulted, which is what makes the
        // migration additive against the rows both dev sites already carry.
        self::assertTrue($table->column('job_title')->nullable);
        self::assertTrue($table->column('manager_user_id')->nullable);
        self::assertTrue($table->column('is_primary')->hasDefault);

        $indexed = array_map(static fn ($index): array => $index->columns, $table->indexes);
        self::assertContains(['organisation_id', 'manager_user_id'], $indexed);
    }

    public function testAMemberIsCreatedWithEveryDirectoryAttributeItWasGiven(): void
    {
        $scope = $this->organisation();
        $members = $this->members();
        $manager = $members->add(Principal::user(1), $scope->organisationId, [
            'expected_revision' => 1,
            'user_id' => 2,
            'role_key' => 'org_admin',
        ]);
        self::assertSame(2, (int) $manager['item']['user_id']);

        $created = $members->add(Principal::user(1), $scope->organisationId, [
            'expected_revision' => 2,
            'user_id' => 3,
            'role_key' => 'org_member',
            'job_title' => 'Staff Engineer',
            'department_label' => 'Platform',
            'division' => 'Engineering',
            'organization_label' => 'Northlight Health',
            'cost_centre' => 'CC-4021',
            'employee_number' => 'E-99',
            'manager_user_id' => 2,
            'is_primary' => true,
            'begins_at' => '2026-02-01 00:00:00',
            'ends_at' => '2026-12-31 00:00:00',
        ]);

        $item = $created['item'];
        self::assertSame('Staff Engineer', $item['job_title']);
        self::assertSame('Platform', $item['department_label']);
        self::assertSame('Engineering', $item['division']);
        self::assertSame('Northlight Health', $item['organization_label']);
        self::assertSame('CC-4021', $item['cost_centre']);
        self::assertSame('E-99', $item['employee_number']);
        self::assertSame(2, $item['manager_user_id']);
        self::assertSame(1, $item['is_primary']);
        self::assertSame('2026-02-01 00:00:00', $item['begins_at']);
    }

    public function testAManagerFromAnotherOrganisationIsRefusedRatherThanStored(): void
    {
        $first = $this->organisation();
        $second = $this->organisation('two');
        $members = $this->members();

        // User 4 is an active member of the second organisation and of
        // nothing in the first, which is the exact shape that would have
        // routed the first organisation's reminders out of it.
        $members->add(Principal::user(1), $second->organisationId, [
            'expected_revision' => 1,
            'user_id' => 4,
            'role_key' => 'org_member',
        ]);

        try {
            $members->add(Principal::user(1), $first->organisationId, [
                'expected_revision' => 1,
                'user_id' => 3,
                'role_key' => 'org_member',
                'manager_user_id' => 4,
            ]);
            self::fail('A manager outside this organisation should have been refused.');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('manager_user_id', $refusal->errors);
            self::assertNull($this->store->activeMemberId($first, 3));
        }
    }

    public function testARefusedManagerLeavesNoMembershipBehind(): void
    {
        $scope = $this->organisation();
        $members = $this->members();

        try {
            $members->add(Principal::user(1), $scope->organisationId, [
                'expected_revision' => 1,
                'user_id' => 3,
                'role_key' => 'org_member',
                'manager_user_id' => 7,
            ]);
            self::fail('A manager with no membership here should have been refused.');
        } catch (ValidationException) {
            // The whole write is one transaction, so the refusal is not a
            // half-created member with a missing manager.
            self::assertNull($this->store->activeMemberId($scope, 3));
        }
    }

    public function testAPatchTouchesOnlyTheAttributesItNames(): void
    {
        $scope = $this->organisation();
        $members = $this->members();
        $created = $members->add(Principal::user(1), $scope->organisationId, [
            'expected_revision' => 1,
            'user_id' => 3,
            'role_key' => 'org_member',
            'job_title' => 'Analyst',
            'cost_centre' => 'CC-1',
        ]);
        $memberId = (int) $created['item']['id'];

        $patched = $members->update(Principal::user(1), $scope->organisationId, $memberId, [
            'expected_revision' => 2,
            'job_title' => 'Senior Analyst',
        ]);

        self::assertSame('Senior Analyst', $patched['item']['job_title']);
        self::assertSame('CC-1', $patched['item']['cost_centre']);
    }

    public function testAnExplicitNullClearsAnAttributeAndAnAbsentKeyDoesNot(): void
    {
        $scope = $this->organisation();
        $members = $this->members();
        $created = $members->add(Principal::user(1), $scope->organisationId, [
            'expected_revision' => 1,
            'user_id' => 3,
            'role_key' => 'org_member',
            'job_title' => 'Analyst',
            'division' => 'Engineering',
        ]);
        $memberId = (int) $created['item']['id'];

        $cleared = $members->update(Principal::user(1), $scope->organisationId, $memberId, [
            'expected_revision' => 2,
            'division' => null,
        ]);

        self::assertNull($cleared['item']['division']);
        self::assertSame('Analyst', $cleared['item']['job_title']);
    }

    public function testAnEndBeforeItsBeginningIsRefused(): void
    {
        try {
            MembershipDirectory::fromInput([
                'begins_at' => '2026-06-01 00:00:00',
                'ends_at' => '2026-01-01 00:00:00',
            ]);
            self::fail('An end before its beginning should have been refused.');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('ends_at', $refusal->errors);
        }
    }

    public function testAnOverLongAttributeIsRefusedRatherThanTruncated(): void
    {
        try {
            MembershipDirectory::fromInput(['cost_centre' => str_repeat('x', 101)]);
            self::fail('An over-long attribute should have been refused rather than truncated.');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('cost_centre', $refusal->errors);
        }
    }

    private function members(): ManageOrganisationMembers
    {
        $access = new \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess($this->resolver);

        return new ManageOrganisationMembers(
            $access,
            $this->store,
            $this->roles,
            $this->env->users,
            $this->env->translator,
        );
    }

    private function organisation(string $slug = 'one'): OrgScope
    {
        $id = $this->store->create([
            'name' => 'Organisation ' . $slug,
            'slug' => 'org-' . $slug,
            'created_by' => 1,
        ]);

        return OrgScope::fromResolver($this->resolver, Principal::user(1), $id, 'people');
    }
}
