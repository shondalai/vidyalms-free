<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\ComplianceAssignments;
use Shondalai\VidyaLMS\Core\Application\Organisation\CourseReleasePolicy;
use Shondalai\VidyaLMS\Core\Application\Organisation\ExportOrganisationAudit;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationCompliance;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceState;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceStatus;
use Shondalai\VidyaLMS\Core\Domain\Compliance\ComplianceSubject;
use Shondalai\VidyaLMS\Core\Domain\Compliance\RenewalStatus;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\ComplianceStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\AbsoluteAnchor;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\CourseStartAnchor;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\EnrolmentAnchor;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\ComplianceWindowTask;
use Shondalai\VidyaLMS\Core\Registry\ComplianceRule\ComplianceRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorRegistry;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The core half of the R11a review, closed.
 *
 * Every case here failed before the change beside it and passes after. They are
 * together because they are one wave's findings rather than one subject: three
 * are seams a package could not reach, three are a seam disagreeing with its own
 * documentation, and the first is a tenancy widening a Pro role made visible.
 */
final class Wave11aReviewFindingsTest extends TestCase
{
    private TestPorts $env;
    private OrganisationStore $store;
    private ComplianceRuleRegistry $rules;
    private ManageOrganisationCompliance $compliance;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->store = new OrganisationStore($this->env->database, $this->env->clock);

        foreach ([1, 2, 3, 4] as $id) {
            $this->env->users->add($id, 'Synthetic User ' . $id, 'user' . $id . '@example.test');
        }

        $this->env->authorization->grant(1, Capability::MANAGE);

        foreach ([2, 3, 4] as $id) {
            $this->env->authorization->grant($id, Capability::MANAGE_GROUPS);
        }

        $anchors = new ScheduleAnchorRegistry();
        $anchors->register(new AbsoluteAnchor());
        $anchors->register(new EnrolmentAnchor());
        $anchors->register(new CourseStartAnchor());
        $this->rules = new ComplianceRuleRegistry();

        $this->compliance = new ManageOrganisationCompliance(
            $this->resolver(),
            $this->store,
            new OrganisationLearningStore($this->env->database, $this->env->clock, $this->env->random),
            new ComplianceAssignments($this->env->database, $this->env->clock, $this->rules, $anchors),
            $this->rules,
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
     * An on-time completion could never be recorded compliant.
     *
     * The rules answer `compliant`, the machine had no `assigned -> compliant`
     * edge and nothing in the product ever wrote `in_progress`, so the sweep
     * refused the write on every run and the row stayed `assigned` for ever.
     */
    public function testAnOnTimeCompletionMovesStraightFromAssignedToCompliant(): void
    {
        self::assertTrue(
            ComplianceStatus::permits(ComplianceStatus::ASSIGNED, ComplianceStatus::COMPLIANT),
            'the only state a completion can be seen from is the state every assignment starts in',
        );

        $org = $this->organisation('acme');
        $this->release($org, 1);
        $this->member($org, 2);
        $this->entitle($org, 2, 1);
        $this->enrol(2, 1);
        $this->rules->register(new AlwaysCompliantRule());
        $requirement = $this->compliance->create($this->owner(), $org, [
            'title' => 'Induction',
            'course_id' => 1,
            'rule_key' => AlwaysCompliantRule::KEY,
            'rule_params' => ['days' => 30],
        ]);
        $assigned = $this->compliance->assign($this->owner(), $org, (int) $requirement['id'], ['user_ids' => [2]]);
        self::assertSame(1, $assigned['assigned']);
        self::assertSame(ComplianceStatus::ASSIGNED, $assigned['items'][0]['compliance_status']);
        $this->complete(2, 1, '2026-04-02 10:00:00');

        $summary = $this->sweep()->run(new ScheduledTaskRun(1, [], Principal::user(1)));
        $row = $this->env->database->selectOne(Query::from('compliance_assignments'));

        self::assertIsArray($row);
        self::assertStringContainsString('refused=0', $summary);
        self::assertSame(ComplianceStatus::COMPLIANT, $row['compliance_status']);
    }

    /**
     * The completion listener `ComplianceState` names had never been written,
     * so `completed_at` was null on every row that had ever existed and no rule
     * could answer compliant about a real person however well they had done.
     */
    public function testTheSweepStampsTheCompletionTheEnrolmentAlreadyCarries(): void
    {
        $org = $this->organisation('acme');
        $this->release($org, 1);
        $this->member($org, 2);
        $this->entitle($org, 2, 1);
        $this->enrol(2, 1);
        $this->rules->register(new AlwaysCompliantRule());
        $requirement = $this->compliance->create($this->owner(), $org, [
            'title' => 'Induction',
            'course_id' => 1,
            'rule_key' => AlwaysCompliantRule::KEY,
            'rule_params' => ['days' => 30],
        ]);
        $this->compliance->assign($this->owner(), $org, (int) $requirement['id'], ['user_ids' => [2]]);
        $this->complete(2, 1, '2026-04-02 10:00:00');

        $summary = $this->sweep()->run(new ScheduledTaskRun(1, [], Principal::user(1)));
        $row = $this->env->database->selectOne(Query::from('compliance_assignments'));

        self::assertIsArray($row);
        self::assertStringContainsString('completions=1', $summary);
        self::assertSame('2026-04-02 10:00:00', (string) $row['completed_at']);
    }

    /**
     * A rule whose renewal word is refused keeps the compliance status it
     * earned.
     *
     * The R11b finding: `write()` refused when either axis rejected a
     * transition, so a rule computing its answer from the expiry, which is the
     * natural shape, had the whole write refused and the accepted `compliant`
     * went with the refused renewal word, on every later run for ever. The
     * axes are applied independently now and the summary names which one
     * refused.
     */
    public function testARuleThatSkipsTheNoticeBandKeepsTheStatusItEarned(): void
    {
        $org = $this->organisation('acme');
        $this->release($org, 1);
        $this->member($org, 2);
        $this->entitle($org, 2, 1);
        $this->enrol(2, 1);
        $this->rules->register(new BandSkippingRule());
        $requirement = $this->compliance->create($this->owner(), $org, [
            'title' => 'Induction',
            'course_id' => 1,
            'rule_key' => BandSkippingRule::KEY,
            'rule_params' => [],
        ]);
        $this->compliance->assign($this->owner(), $org, (int) $requirement['id'], ['user_ids' => [2]]);
        $this->complete(2, 1, '2026-04-02 10:00:00');

        $summary = $this->sweep()->run(new ScheduledTaskRun(1, [], Principal::user(1)));
        $row = $this->env->database->selectOne(Query::from('compliance_assignments'));

        self::assertIsArray($row);
        self::assertSame(ComplianceStatus::COMPLIANT, (string) $row['compliance_status']);
        // The refused word is named rather than counted as a whole refusal, and
        // the row keeps the band it was standing in.
        self::assertStringContainsString('renewal_refused=1', $summary);
        self::assertStringContainsString('refused=0', $summary);
        self::assertSame(RenewalStatus::NOT_DUE, (string) $row['renewal_status']);
        self::assertNotNull($row['due_at'], 'the dates the accepted axis carried were discarded too');
    }

    /** And the rule can see the band it is standing in, so it can walk it. */
    public function testARuleIsToldWhereTheRenewalAxisIsStanding(): void
    {
        $subject = ComplianceSubject::of(1, 1, 2, new \DateTimeImmutable('2026-01-01 00:00:00'));

        self::assertSame(RenewalStatus::NOT_DUE, $subject->renewalStatus);
        self::assertSame(
            RenewalStatus::DUE,
            ComplianceSubject::of(
                1,
                1,
                2,
                new \DateTimeImmutable('2026-01-01 00:00:00'),
                1,
                null,
                [],
                1,
                RenewalStatus::DUE,
            )->renewalStatus,
        );
    }

    /**
     * `ComplianceRuleInterface::paramsSchema()` had zero callers in the tree,
     * so a rule could publish a closed schema requiring `days` and the seam
     * accepted `{dayz: "thirty"}` with a 201.
     */
    public function testTheComplianceSeamChecksTheRuleOwnSchemaAndStampsItsOwnShape(): void
    {
        $org = $this->organisation('acme');
        $this->release($org, 1);
        $this->rules->register(new AlwaysCompliantRule());

        $requirement = $this->compliance->create($this->owner(), $org, [
            'title' => 'Induction',
            'course_id' => 1,
            'rule_key' => AlwaysCompliantRule::KEY,
            'rule_params' => ['days' => 30],
        ]);
        $row = $this->env->database->selectOne(
            Query::from('compliance_requirements')->where('id', '=', (int) $requirement['id']),
        );

        self::assertIsArray($row);
        self::assertSame(
            AlwaysCompliantRule::SHAPE,
            (int) $row['rule_params_version'],
            'the column mirrors the rule that owns the bag, not the seam that stored it',
        );

        $this->expectException(ValidationException::class);
        $this->compliance->create($this->owner(), $org, [
            'title' => 'Mistyped',
            'course_id' => 1,
            'rule_key' => AlwaysCompliantRule::KEY,
            'rule_params' => ['dayz' => 'thirty'],
        ]);
    }

    /**
     * The four note keys the registry and every rule write reached no human:
     * nothing stored them, nothing returned them and none was in a catalogue.
     */
    public function testTheMissDirectionIsStoredReturnedAndInTheCatalogue(): void
    {
        $org = $this->organisation('acme');
        $this->release($org, 1);
        $this->member($org, 2);
        $this->entitle($org, 2, 1);
        $this->enrol(2, 1);
        $requirement = $this->compliance->create($this->owner(), $org, [
            'title' => 'Induction',
            'course_id' => 1,
        ]);
        $this->compliance->assign($this->owner(), $org, (int) $requirement['id'], ['user_ids' => [2]]);

        $page = $this->compliance->assignments($this->owner(), $org);
        $item = $page['items'][0];

        self::assertContains('vidyalms.compliance.no_rule', $item['notes']);
        self::assertArrayNotHasKey(
            'rule_state',
            $item,
            'the provider memory stays where the schema doctrine puts it',
        );

        $catalogue = require \dirname(__DIR__, 3) . '/i18n/catalogue.php';

        foreach (
            [
                'vidyalms.compliance.no_rule',
                'vidyalms.compliance.rule_missing',
                'vidyalms.compliance.unresolved',
                'vidyalms.compliance.completed_late',
            ] as $key
        ) {
            self::assertArrayHasKey($key, $catalogue, $key);
        }
    }

    /** The core's own note key never overwrites, and is never handed back to, the provider. */
    public function testTheNoteKeyIsReservedAgainstTheProvidersOwnBody(): void
    {
        $state = ComplianceState::of(
            ComplianceStatus::ASSIGNED,
            RenewalStatus::NOT_DUE,
            ruleState: [ComplianceState::NOTES_KEY => ['a provider wrote this'], 'anchor' => 'x'],
            notes: ['vidyalms.compliance.completed_late'],
        );
        $stored = $state->storedRuleState();

        self::assertSame(['vidyalms.compliance.completed_late'], ComplianceState::notesOf($stored));
        self::assertSame(['anchor' => 'x'], ComplianceState::ruleBodyOf($stored));
    }

    /**
     * `ConnectionProtocol::PROTOCOLS` was the whole storable vocabulary and
     * `isKnown()` ran before the capability probe, so a package terminating a
     * federation the core does not spell could record no connection at all.
     */
    public function testAPackageMayStoreTheFederationWordItDeclaresItTerminates(): void
    {
        self::assertTrue(
            \Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\ConnectionProtocol::isStorable('cas'),
            'a word the core does not spell is still a word',
        );
        self::assertFalse(
            \Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\ConnectionProtocol::isKnown('cas'),
            'and it is still not one of the core own four',
        );

        foreach (['', 'CAS', 'https://idp.example.test/saml', 'a', str_repeat('x', 21), 'sa ml'] as $refused) {
            self::assertFalse(
                \Shondalai\VidyaLMS\Core\Domain\Organisation\Identity\ConnectionProtocol::isStorable($refused),
                $refused,
            );
        }
    }

    /**
     * A package could declare an organisation-owned table `scoped` in its own
     * `schema/org-tenancy.php`, the CI gate read that declaration into the
     * reads rule, and the reader threw on every read of it. Declaring the
     * tenancy correctly was the one thing a package could not do.
     */
    public function testAPackageDeclaredScopedTableIsReadableThroughTheReader(): void
    {
        $org = $this->organisation('acme');
        $scope = $this->scope($org);

        OrgScopedReader::contributeDeclaration(['scoped' => ['acme_org_notes' => 'organisation_id']]);

        self::assertTrue(OrgScopedReader::owns('acme_org_notes'));
        self::assertArrayHasKey('acme_org_notes', OrgScopedReader::tables());

        $query = OrgScopedReader::from($scope, 'acme_org_notes');
        self::assertInstanceOf(Query::class, $query);

        // Idempotent for the same column, and a disagreement about which column
        // carries the tenant is a build failure rather than last writer wins.
        OrgScopedReader::contributeDeclaration(['scoped' => ['acme_org_notes' => 'organisation_id']]);
        $this->expectException(\LogicException::class);
        OrgScopedReader::contribute('acme_org_notes', 'org_id');
    }

    /** A table nobody declared is still refused, which is what the reader is for. */
    public function testATableNobodyDeclaredIsStillRefused(): void
    {
        $scope = $this->scope($this->organisation('acme'));

        $this->expectException(\LogicException::class);
        OrgScopedReader::from($scope, 'acme_undeclared_notes');
    }

    /**
     * `org_role_assignments.params` shipped with `OrganisationStore::setRole()`
     * taking a payload, `roleParams()` reading one, and no route on any host
     * able to write one: a parameterised role could be registered and never
     * configured.
     */
    public function testAParameterisedRoleCanBeConfiguredThroughTheMemberRoute(): void
    {
        $roles = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $roles->register($role);
        }

        $roles->register(new ParameterisedTestRole());
        $members = $this->members($roles);
        $org = $this->organisation('acme');
        $memberId = $this->store->addMember($this->scope($org), 2, 'org_member', 'manual', null);

        $updated = $members->update($this->owner(), $org, $memberId, [
            'expected_revision' => $this->revision($org),
            'role_key' => ParameterisedTestRole::KEY,
            'role_params' => ['group_ids' => [4, 9]],
        ]);

        self::assertSame([4, 9], $updated['item']['role_params']['params']['group_ids']);
        self::assertSame(ParameterisedTestRole::SHAPE, $updated['item']['role_params']['params_version']);

        $stored = $this->env->database->selectOne(
            Query::from('org_role_assignments')->where('membership_id', '=', $memberId),
        );
        self::assertIsArray($stored);
        self::assertSame(
            ParameterisedTestRole::SHAPE,
            StoredShape::versionOf((array) json_decode((string) $stored['params'], true)),
        );
    }

    /** The payload is checked against the role's own closed schema. */
    public function testARolePayloadTheProviderNeverDeclaredIsRefused(): void
    {
        $roles = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $roles->register($role);
        }

        $roles->register(new ParameterisedTestRole());
        $members = $this->members($roles);
        $org = $this->organisation('acme');
        $memberId = $this->store->addMember($this->scope($org), 2, 'org_member', 'manual', null);

        $this->expectException(ValidationException::class);
        $members->update($this->owner(), $org, $memberId, [
            'expected_revision' => $this->revision($org),
            'role_key' => ParameterisedTestRole::KEY,
            'role_params' => ['group_idz' => [4]],
        ]);
    }

    /**
     * The audit export read the same table as the paged event read and did not
     * narrow on the subtree, so a caller with a unit-scoped assignment saw one
     * unit on the screen and the whole organisation in the file.
     */
    public function testTheAuditExportNarrowsOnTheSubtreeTheScreenNarrowsOn(): void
    {
        $org = $this->organisation('acme');
        $siteScope = $this->scope($org);
        $bristol = $this->unit($siteScope, 'Bristol');
        $leeds = $this->unit($siteScope, 'Leeds');
        $this->store->addMember($siteScope, 3, 'org_member', 'manual', null, $bristol);
        $this->store->addMember($siteScope, 4, 'org_member', 'manual', null, $leeds);
        $unitManager = $this->store->addMember($siteScope, 2, 'org_member', 'manual', null, $bristol);

        $roles = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $roles->register($role);
        }

        $roles->register(new SubtreeAuditorRole());
        $this->store->setRole($siteScope, $unitManager, SubtreeAuditorRole::KEY, $bristol);

        $resolver = new OrgScopeResolver(new AccessGuard($this->env->authorization), $this->store, $roles);
        $scope = $resolver->require(Principal::user(2), $org, 'events');
        self::assertSame($bristol, $scope->unitId, 'the assignment is a subtree one');

        $this->event($siteScope, 'member.joined', $bristol);
        $this->event($siteScope, 'member.joined', $leeds);
        $this->event($siteScope, 'organisation.created', null);

        $export = new ExportOrganisationAudit($resolver, $this->store);
        $csv = $export->execute(Principal::user(2), $org, 1, 50, 0, '');

        self::assertSame(1, $csv['total'], 'the Leeds row and the organisation-wide row are outside the subtree');
        self::assertSame(
            $this->store->events($scope, 1, 50)['total'],
            $csv['total'],
            'the file and the screen answer the same question',
        );
    }

    /**
     * Three organisation-wide reads sit behind the `events` right over tables
     * with no unit column at all, so a subtree scope was served the whole
     * tenant's ledger, reconciliation and cleanup queue.
     */
    public function testAnOrganisationWideReadRefusesASubtreeScope(): void
    {
        $org = $this->organisation('acme');
        $siteScope = $this->scope($org);
        $bristol = $this->unit($siteScope, 'Bristol');
        $memberId = $this->store->addMember($siteScope, 2, 'org_member', 'manual', null, $bristol);

        $roles = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $roles->register($role);
        }

        $roles->register(new SubtreeAuditorRole());
        $this->store->setRole($siteScope, $memberId, SubtreeAuditorRole::KEY, $bristol);
        $resolver = new OrgScopeResolver(new AccessGuard($this->env->authorization), $this->store, $roles);

        self::assertNotNull($resolver->require(Principal::user(2), $org, 'events')->unitId);

        $this->expectException(EntityNotFoundException::class);
        $resolver->requireOrganisationWide(Principal::user(2), $org, 'events');
    }

    /** An organisation-scoped assignment still reaches the same read. */
    public function testAnOrganisationScopedAssignmentStillReachesTheOrganisationWideRead(): void
    {
        $org = $this->organisation('acme');
        $resolver = $this->resolver();

        self::assertNull($resolver->requireOrganisationWide($this->owner(), $org, 'events')->unitId);
    }

    private function sweep(): ComplianceWindowTask
    {
        return new ComplianceWindowTask(
            new ComplianceStore($this->env->database),
            $this->env->clock,
            $this->rules,
        );
    }

    private function members(OrgRoleRegistry $roles): \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers
    {
        return new \Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers(
            new \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess(
                new OrgScopeResolver(new AccessGuard($this->env->authorization), $this->store, $roles),
            ),
            $this->store,
            $roles,
            $this->env->users,
            $this->env->translator,
        );
    }

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
        return $this->resolver()->require($this->owner(), $organisationId, 'people');
    }

    private function revision(int $organisationId): int
    {
        return (int) $this->store->organisation($this->scope($organisationId))['revision'];
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

    private function event(OrgScope $scope, string $key, ?int $unitId): void
    {
        $this->store->event(
            $scope,
            (int) $this->store->organisation($scope)['revision'],
            $key,
            null,
            [],
            \Shondalai\VidyaLMS\Core\Application\Organisation\MembershipEventContext::manual($unitId),
        );
    }

    /** A root unit and its own closure row, which is what a one-level tree is. */
    private function unit(OrgScope $scope, string $name): int
    {
        $now = $this->store->now();
        $id = $this->env->database->insert('org_units', [
            'organisation_id' => $scope->organisationId,
            'parent_id' => null,
            'name' => $name,
            'path' => $name,
            'path_digest' => hash('sha256', $scope->organisationId . ':' . $name),
            'depth' => 1,
            'state' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->env->database->insert('org_unit_closure', [
            'organisation_id' => $scope->organisationId,
            'ancestor_id' => $id,
            'descendant_id' => $id,
            'depth' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    private function enrol(int $userId, int $courseId): void
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->env->database->insert('enrolments', [
            'user_id' => $userId,
            'course_id' => $courseId,
            'entitlement_id' => 0,
            'status' => 'active',
            'enrolled_at' => $now,
            'completed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** The learner finishes, which nothing has ever told the compliance row about. */
    private function complete(int $userId, int $courseId, string $completedAt): void
    {
        $this->env->database->update(
            'enrolments',
            ['status' => 'completed', 'completed_at' => $completedAt],
            ['user_id' => $userId, 'course_id' => $courseId],
        );
    }

    /** The organisation-sourced grant a course requirement is assignable over. */
    private function entitle(int $organisationId, int $userId, int $courseId): void
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->env->database->insert('entitlements', [
            'user_id' => $userId,
            'course_id' => $courseId,
            'source' => 'organisation',
            'source_ref' => null,
            'status' => 'active',
            'granted_by' => 1,
            'organisation_id' => $organisationId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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
