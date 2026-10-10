<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class MyOrganisationLearningTest extends TestCase
{
    private TestPorts $env;
    private CoreKernel $kernel;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->kernel = new CoreKernel($this->env->ports);
        foreach ([1, 2] as $id) {
            $this->add('organisations', ['id' => $id, 'name' => 'Organization ' . $id, 'slug' => 'org-' . $id, 'root_id' => $id, 'created_by' => 1]);
            $this->add('org_memberships', ['id' => $id, 'organisation_id' => $id, 'user_id' => 7, 'joined_at' => '2026-01-01 00:00:00']);
        }
        foreach ([1, 2, 3, 4, 5, 6] as $id) {
            $this->add('courses', ['id' => $id, 'title' => 'Course ' . $id, 'slug' => 'course-' . $id, 'state' => $id === 4 ? 'draft' : 'published', 'created_by' => 1]);
        }
        $grant = $this->grant(1, 1);
        $this->grant(1, 2); // Same course, another organization, one shared enrolment.
        $this->grant(1, 1); // Repeated grants must not duplicate the filter result.
        $this->add('enrolments', ['user_id' => 7, 'course_id' => 1, 'entitlement_id' => $grant, 'enrolled_at' => '2026-01-01 00:00:00', 'progress_percent' => 40]);
        $personal = $this->grant(6, null);
        $this->add('enrolments', ['user_id' => 7, 'course_id' => 6, 'entitlement_id' => $personal, 'enrolled_at' => '2026-01-01 00:00:00']);
        $expired = $this->grant(5, 1, ['expires_at' => '2026-01-10 00:00:00']);
        $this->add('enrolments', ['user_id' => 7, 'course_id' => 5, 'entitlement_id' => $expired, 'enrolled_at' => '2026-01-01 00:00:00']);
        foreach ([1, 2, 3, 4] as $courseId) {
            $this->add('org_course_releases', ['organisation_id' => 1, 'course_id' => $courseId, 'released_by' => 1]);
            $this->add('org_catalogue_entries', ['organisation_id' => 1, 'scope_type' => $courseId === 3 ? 'unit' : 'organisation',
                'scope_id' => $courseId === 3 ? 99 : 1, 'source_key' => 'course', 'course_id' => $courseId, 'created_by' => 1]);
        }
        $requirement = $this->add('compliance_requirements', ['organisation_id' => 1, 'scope_id' => 1, 'title' => 'Safety', 'course_id' => 1, 'created_by' => 1]);
        $this->add('compliance_assignments', ['requirement_id' => $requirement, 'organisation_id' => 1, 'user_id' => 7,
            'assigned_at' => '2026-01-01 00:00:00', 'due_at' => '2026-02-01 00:00:00']);
    }

    public function testMembershipIsNotEnrolmentAndOfferingsRespectAudienceAndPublication(): void
    {
        $page = $this->kernel->myOrganisationLearning->page(Principal::user(7), 1, 'available');
        self::assertSame([2], array_column($page['items'], 'course_id'));
        self::assertFalse($page['items'][0]['can_learn']);
        self::assertSame(['assigned' => 2, 'available' => 1, 'required' => 1], $page['summary']);
        self::assertSame(3, $this->kernel->listMyEnrolments->page(Principal::user(7))['total']);
    }

    public function testAssignedCardsIncludeProgressDeadlineAndExpiredAccess(): void
    {
        $page = $this->kernel->myOrganisationLearning->page(Principal::user(7), 1);
        self::assertSame([1, 5], array_column($page['items'], 'course_id'));
        self::assertSame(40.0, $page['items'][0]['progress_percent']);
        self::assertTrue($page['items'][0]['required']);
        self::assertTrue($page['items'][0]['can_learn']);
        self::assertSame('2026-02-01 00:00:00', $page['items'][0]['due_at']);
        self::assertFalse($page['items'][1]['can_learn']);
        self::assertSame('2026-01-10 00:00:00', $page['items'][1]['expires_at']);
    }

    public function testFiltersAndProvenanceIncludeSecondaryGrantsWithoutDuplicatingCourses(): void
    {
        $all = $this->kernel->listMyEnrolments->page(Principal::user(7));
        self::assertSame(3, $all['total']);
        $second = $this->kernel->listMyEnrolments->page(Principal::user(7), organisationId: 2);
        self::assertSame(1, $second['total']);
        self::assertSame([1, 2], array_column($second['items'][0]['organisations'], 'id'));
        self::assertSame(2, $this->kernel->listMyEnrolments->page(Principal::user(7), organisationId: 1)['total']);
        self::assertSame([6], array_column($this->kernel->listMyEnrolments->page(Principal::user(7), organisationId: 0)['items'], 'course_id'));
        self::assertSame(0, $this->kernel->listMyEnrolments->page(Principal::user(8), organisationId: 1)['total']);
    }

    public function testOwnLearningDoesNotGrantOrganizationAdministration(): void
    {
        $this->kernel->myOrganisationLearning->page(Principal::user(7), 1);
        $this->expectException(EntityNotFoundException::class);
        $this->kernel->organisationCatalogue->courses(Principal::user(7), 1);
    }

    public function testExpiredMembershipDisappearsFromDiscoveryAndCannotOpenLearning(): void
    {
        $this->env->database->update('org_memberships', ['ends_at' => '2026-01-10 00:00:00'], ['id' => 1]);
        self::assertSame([2], array_column($this->kernel->myOrganisationLearning->organisations(Principal::user(7), 1, 20)['items'], 'id'));
        $this->expectException(EntityNotFoundException::class);
        $this->kernel->myOrganisationLearning->page(Principal::user(7), 1);
    }

    public function testSearchPaginationAndWithdrawnOfferings(): void
    {
        $page = $this->kernel->myOrganisationLearning->page(Principal::user(7), 1, 'assigned', 999, 1, 'Course 1');
        self::assertSame(1, $page['page']);
        self::assertSame([1], array_column($page['items'], 'course_id'));
        $this->env->database->update('org_course_releases', ['state' => 'withdrawn'], ['organisation_id' => 1, 'course_id' => 2]);
        self::assertSame([], $this->kernel->myOrganisationLearning->page(Principal::user(7), 1, 'available')['items']);
    }

    public function testWithdrawnReleaseRetainsHistoryButDoesNotOfferLearningAndExemptRequirementsAreNotOutstanding(): void
    {
        $this->env->database->update('org_course_releases', ['state' => 'withdrawn'], ['organisation_id' => 1, 'course_id' => 1]);
        $this->env->database->update('compliance_assignments', ['compliance_status' => 'exempt'], ['organisation_id' => 1, 'user_id' => 7]);
        $page = $this->kernel->myOrganisationLearning->page(Principal::user(7), 1);
        self::assertFalse($page['items'][0]['can_learn']);
        self::assertSame(40.0, $page['items'][0]['progress_percent']);
        self::assertSame(0, $page['summary']['required']);
    }

    /** @param array<string, mixed> $values */
    private function grant(int $courseId, ?int $orgId, array $values = []): int
    {
        return $this->add('entitlements', $values + ['user_id' => 7, 'course_id' => $courseId, 'source' => $orgId === null ? 'manual' : 'org', 'organisation_id' => $orgId, 'org_membership_id' => $orgId]);
    }

    /** @param array<string, mixed> $values */
    private function add(string $table, array $values): int
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        return $this->env->database->insert($table, $values + ['created_at' => $now, 'updated_at' => $now]);
    }
}
