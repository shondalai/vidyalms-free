<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\ComplianceAssignments;
use Shondalai\VidyaLMS\Core\Application\Organisation\CourseReleasePolicy;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationCompliance;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationComplianceRoutes;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Compliance\AssignmentExceptionKind;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\AbsoluteAnchor;
use Shondalai\VidyaLMS\Core\Registry\ComplianceRule\ComplianceRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorRegistry;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\CourseStartAnchor;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\EnrolmentAnchor;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The compliance authoring surface.
 *
 * It exists because the seam did not have one: four tables, two state
 * machines, an exception vocabulary and a daily sweep shipped with no writer,
 * so `ComplianceAssignments::assign` had no caller anywhere and the window
 * task reported `checked=0` on every site in the world.
 *
 * The free build registers no rule, so every case here is about recording the
 * requirement, refusing what cannot be recorded, and raising an exception
 * rather than skipping a person.
 */
final class OrganisationComplianceAuthoringTest extends TestCase
{
    private TestPorts $env;
    private OrganisationStore $store;
    private ManageOrganisationCompliance $compliance;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->store = new OrganisationStore($this->env->database, $this->env->clock);

        foreach ([1, 2, 3] as $id) {
            $this->env->users->add($id, 'Synthetic User ' . $id, 'user' . $id . '@example.test');
        }

        $this->env->authorization->grant(1, Capability::MANAGE);

        $anchors = new ScheduleAnchorRegistry();
        $anchors->register(new AbsoluteAnchor());
        $anchors->register(new EnrolmentAnchor());
        $anchors->register(new CourseStartAnchor());

        $learningStore = new OrganisationLearningStore($this->env->database, $this->env->clock, $this->env->random);
        $rules = new ComplianceRuleRegistry();

        $this->compliance = new ManageOrganisationCompliance(
            $this->resolver(),
            $this->store,
            $learningStore,
            new ComplianceAssignments($this->env->database, $this->env->clock, $rules, $anchors),
            $rules,
            $anchors,
            new CourseReleasePolicy($this->env->database, $this->env->clock),
        );

        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->env->database->insert('courses', [
            'title' => 'Fire Safety',
            'slug' => 'fire-safety',
            'state' => 'published',
            'created_by' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * The free answer, stated. No rule is registered, the requirement records
     * `none`, and nothing anywhere reports anybody compliant.
     */
    public function testAFreeRequirementIsRecordedUntrackedAndTracksNobody(): void
    {
        $org = $this->organisation('acme');
        $this->release($org, 1);
        $requirement = $this->compliance->create($this->owner(), $org, [
            'title' => 'Annual fire safety',
            'course_id' => 1,
        ]);

        self::assertSame('none', $requirement['rule_key']);
        self::assertTrue($requirement['rule_resolved'], '"none" is the answer the free build means, not a miss');
        self::assertSame('mandatory', $requirement['requirement']);
        self::assertSame(0, $requirement['assignment_count']);
        self::assertSame(ComplianceAssignments::DEFAULT_ANCHOR, $requirement['anchor_key']);
        self::assertSame(
            1,
            $this->env->database->count(Query::from('compliance_requirements')),
            'the table that had no writer now has one',
        );
    }

    /** A course this organisation was never released cannot be required of anybody. */
    public function testARequirementNamingAnUnreleasedCourseIsRefused(): void
    {
        $org = $this->organisation('acme');

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessageMatches('/forbidden_scope/');
        $this->compliance->create($this->owner(), $org, ['title' => 'Unreleased', 'course_id' => 1]);
    }

    /**
     * The anchor half of the R5 finding: a requirement with no course used to
     * raise `anchor_unresolved` on every assignment, for ever, because the
     * default anchor is the person's own enrolment and there is no enrolment
     * to read.
     */
    public function testARequirementWithNoCourseMustNameAnAnchorThatDoesNotNeedOne(): void
    {
        $org = $this->organisation('acme');

        try {
            $this->compliance->create($this->owner(), $org, ['title' => 'Policy sign-off']);
            self::fail('a courseless requirement on the enrolment anchor can never resolve');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('rule_params.anchor', $refusal->errors);
        }
    }

    /**
     * The other half: with the absolute anchor and its instant, a requirement
     * with no course assigns, which is what R5 recorded as unreachable.
     */
    public function testAnAbsoluteAnchorMakesACourselessRequirementAssignable(): void
    {
        $org = $this->organisation('acme');
        $this->member($org, 2);
        $requirement = $this->compliance->create($this->owner(), $org, [
            'title' => 'Policy sign-off',
            'rule_params' => [
                'anchor' => AbsoluteAnchor::KEY,
                ComplianceAssignments::ANCHOR_PARAMS => ['at' => '2026-01-01 09:00:00'],
            ],
        ]);

        self::assertSame(AbsoluteAnchor::KEY, $requirement['anchor_key']);
        self::assertTrue($requirement['anchor_resolved']);

        $outcome = $this->compliance->assign($this->owner(), $org, $requirement['id'], ['user_ids' => [2]]);

        self::assertSame(1, $outcome['assigned']);
        self::assertSame(0, $outcome['exceptions']);
        self::assertSame('assigned', $outcome['items'][0]['state']);
        self::assertNotNull($outcome['items'][0]['assignment_id']);
        self::assertNotSame('compliant', $outcome['items'][0]['compliance_status']);
    }

    /**
     * The rule the class is held up by: a bulk call assigns everybody it can
     * and reports every one it could not, rather than throwing on the first.
     */
    public function testABulkAssignmentRaisesExceptionsRatherThanSkippingPeople(): void
    {
        $org = $this->organisation('acme');
        $this->member($org, 2);
        $requirement = $this->compliance->create($this->owner(), $org, [
            'title' => 'Policy sign-off',
            'rule_params' => [
                'anchor' => AbsoluteAnchor::KEY,
                ComplianceAssignments::ANCHOR_PARAMS => ['at' => '2026-01-01 09:00:00'],
            ],
        ]);

        // User 2 is a member; user 3 is not.
        $outcome = $this->compliance->assign($this->owner(), $org, $requirement['id'], ['user_ids' => [2, 3]]);

        self::assertSame(1, $outcome['assigned']);
        self::assertSame(1, $outcome['exceptions']);
        self::assertSame(AssignmentExceptionKind::USER_NOT_IN_ORG, $outcome['items'][1]['exception']);
        self::assertNull($outcome['items'][1]['assignment_id']);

        $exceptions = $this->compliance->exceptions($this->owner(), $org);
        self::assertSame(1, $exceptions['total']);
        self::assertSame(3, (int) $exceptions['items'][0]['user_id']);
        self::assertIsArray($exceptions['items'][0]['resolved_input']);
    }

    /** A person with no organisation grant for the course is an exception, not a silent skip. */
    public function testACourseRequirementWithoutAnOrganisationGrantRaisesNoEntitlement(): void
    {
        $org = $this->organisation('acme');
        $this->release($org, 1);
        $this->member($org, 2);
        $requirement = $this->compliance->create($this->owner(), $org, ['title' => 'Fire', 'course_id' => 1]);
        $outcome = $this->compliance->assign($this->owner(), $org, $requirement['id'], ['user_ids' => [2]]);

        self::assertSame(0, $outcome['assigned']);
        self::assertSame(AssignmentExceptionKind::NO_ENTITLEMENT, $outcome['items'][0]['exception']);
        self::assertNotSame(
            'compliant',
            $outcome['items'][0]['compliance_status'],
            'the absence of an answer is not a pass',
        );
    }

    /** A rule key nothing answers is refused at save rather than stored and ignored. */
    public function testARuleKeyNoInstalledProviderAnswersIsRefused(): void
    {
        $org = $this->organisation('acme');
        $this->release($org, 1);

        try {
            $this->compliance->create($this->owner(), $org, [
                'title' => 'Annual',
                'course_id' => 1,
                'rule_key' => 'due-after-assignment',
            ]);
            self::fail('a rule nothing answers must be refused');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('rule_key', $refusal->errors);
        }
    }

    /** The rule cannot be changed underneath people who are already assigned. */
    public function testTheRuleIsFixedOnceAnybodyIsAssigned(): void
    {
        $org = $this->organisation('acme');
        $this->member($org, 2);
        $requirement = $this->compliance->create($this->owner(), $org, [
            'title' => 'Policy sign-off',
            'rule_params' => [
                'anchor' => AbsoluteAnchor::KEY,
                ComplianceAssignments::ANCHOR_PARAMS => ['at' => '2026-01-01 09:00:00'],
            ],
        ]);
        $this->compliance->assign($this->owner(), $org, $requirement['id'], ['user_ids' => [2]]);

        // The title still moves: it is a label, not a promise about a date.
        $renamed = $this->compliance->update($this->owner(), $org, $requirement['id'], ['title' => 'Policy sign-off v2']);
        self::assertSame('Policy sign-off v2', $renamed['title']);
        self::assertSame(1, $renamed['assignment_count']);

        $this->expectException(ConflictException::class);
        $this->compliance->update($this->owner(), $org, $requirement['id'], [
            'rule_params' => [
                'anchor' => AbsoluteAnchor::KEY,
                ComplianceAssignments::ANCHOR_PARAMS => ['at' => '2027-01-01 09:00:00'],
            ],
        ]);
    }

    /** The assignment list reads through the scoped reader and joins its requirement. */
    public function testTheAssignmentListIsScopedAndCarriesTheRequirementItCameFrom(): void
    {
        $mine = $this->organisation('acme');
        $theirs = $this->organisation('globex');
        $this->member($mine, 2);
        $requirement = $this->compliance->create($this->owner(), $mine, [
            'title' => 'Policy sign-off',
            'rule_params' => [
                'anchor' => AbsoluteAnchor::KEY,
                ComplianceAssignments::ANCHOR_PARAMS => ['at' => '2026-01-01 09:00:00'],
            ],
        ]);
        $this->compliance->assign($this->owner(), $mine, $requirement['id'], ['user_ids' => [2]]);

        $listed = $this->compliance->assignments($this->owner(), $mine);
        self::assertSame(1, $listed['total']);
        self::assertSame('Policy sign-off', $listed['items'][0]['requirement_title']);
        self::assertSame('none', $listed['items'][0]['rule_key']);

        self::assertSame(
            0,
            $this->compliance->assignments($this->owner(), $theirs)['total'],
            'another organisation sees none of it',
        );
        self::assertSame([], $this->compliance->cycles($this->owner(), $mine, (int) $listed['items'][0]['id']));
    }

    /** The route surface is seven, all under the tenancy prefix. */
    public function testTheComplianceSurfaceIsSevenRoutesUnderTheOrganisationsOwnPath(): void
    {
        $definitions = OrganisationComplianceRoutes::definitions();
        self::assertCount(7, $definitions);

        foreach ($definitions as [$method, $path]) {
            self::assertStringContainsString('/manage/organisations/{id}/compliance', $path);
            self::assertContains($method, ['GET', 'POST', 'PATCH']);
        }
    }

    public function testUnitAdministratorCannotReadOtherUnitsAssignmentsOrCycles(): void
    {
        [$org, $unit, $other] = $this->unitAdministrator();
        $requirement = $this->compliance->create($this->owner(), $org, [
            'title' => 'Policy', 'rule_params' => ['anchor' => AbsoluteAnchor::KEY,
                ComplianceAssignments::ANCHOR_PARAMS => ['at' => '2026-01-01 09:00:00']],
        ]);
        $assigned = $this->compliance->assign($this->owner(), $org, $requirement['id'], ['user_ids' => [2, 3]]);
        self::assertSame(2, $assigned['assigned']);
        $rows = $this->compliance->assignments(Principal::user(2), $org);
        self::assertSame(1, $rows['total']);
        self::assertSame(2, (int) $rows['items'][0]['user_id']);
        $this->expectException(\Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException::class);
        $this->compliance->cycles(Principal::user(2), $org, (int) $assigned['items'][1]['assignment_id']);
    }

    public function testUnitAdministratorCannotEditOrganisationWideRequirements(): void
    {
        [$org] = $this->unitAdministrator();
        $requirement = $this->compliance->create($this->owner(), $org, [
            'title' => 'Policy', 'rule_params' => ['anchor' => AbsoluteAnchor::KEY,
                ComplianceAssignments::ANCHOR_PARAMS => ['at' => '2026-01-01 09:00:00']],
        ]);
        $this->expectException(\Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException::class);
        $this->compliance->update(Principal::user(2), $org, $requirement['id'], ['title' => 'Changed outside unit']);
    }

    /** @return array{int, int, int} */
    private function unitAdministrator(): array
    {
        $org = $this->organisation('unit-test');
        $units = [];
        $stamp = $this->env->clock->now()->format('Y-m-d H:i:s');
        foreach ([2, 3] as $user) {
            $unit = $this->env->database->insert('org_units', ['organisation_id' => $org,
                'name' => 'Unit ' . $user, 'path' => '/' . $user, 'depth' => 0, 'path_digest' => hash('sha256', (string) $user),
                'state' => 'active', 'created_at' => $stamp, 'updated_at' => $stamp]);
            $this->env->database->insert('org_unit_closure', ['organisation_id' => $org,
                'ancestor_id' => $unit, 'descendant_id' => $unit, 'depth' => 0, 'created_at' => $stamp, 'updated_at' => $stamp]);
            $member = $this->store->addMember($this->scope($org), $user, 'org_member', 'manual', null, $unit);
            if ($user === 2) {
                $this->store->setRole($this->scope($org), $member, 'org_admin', $unit);
                foreach (Capability::organisationSurface() as $capability) {
                    $this->env->authorization->grant(2, $capability);
                }
            }
            $units[] = $unit;
        }
        return [$org, $units[0], $units[1]];
    }

    // ── Harness ──────────────────────────────────────────────────────────

    private function resolver(): OrgScopeResolver
    {
        $roles = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $roles->register($role);
        }

        return new OrgScopeResolver(new AccessGuard($this->env->authorization), $this->store, $roles);
    }

    private function scope(int $organisationId): OrgScope
    {
        return (new OrganisationAccess($this->resolver()))->require($this->owner(), $organisationId, 'read');
    }

    private function owner(): Principal
    {
        return Principal::user(1);
    }

    private function organisation(string $slug): int
    {
        return $this->store->create(['name' => ucfirst($slug), 'slug' => $slug, 'created_by' => 1]);
    }

    private function member(int $organisationId, int $userId): void
    {
        $this->store->addMember($this->scope($organisationId), $userId, 'org_member', 'manual', null);
    }

    private function release(int $organisationId, int $courseId): void
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->env->database->insert('org_course_releases', [
            'organisation_id' => $organisationId,
            'course_id' => $courseId,
            'released_by' => 1,
            'state' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
