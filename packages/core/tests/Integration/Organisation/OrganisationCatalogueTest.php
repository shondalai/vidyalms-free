<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\CourseReleasePolicy;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationCatalogue;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationCatalogueRoutes;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\Catalogue\CourseGrant;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CourseCatalogueSource;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Provider\Organisation\UnitSubtreeCatalogueSource;
use Shondalai\VidyaLMS\Core\Registry\OrgCatalogueSource\OrgCatalogueSourceRegistry;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The organisation catalogue: what an organisation may grant from, and who
 * decides it.
 *
 * The two rules under test are the two the design states. Nothing an org
 * administrator writes may widen the set a site owner released, and a course
 * reaches an organisation through a registered source rather than through a
 * column. The third case is the one that only shows up on a customer's site:
 * an entry whose source came from a package that has since been uninstalled
 * must leave the organisation intact and report the list as missing.
 */
final class OrganisationCatalogueTest extends TestCase
{
    private TestPorts $env;
    private OrganisationStore $store;
    private OrgCatalogueSourceRegistry $sources;
    private ManageOrganisationCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->store = new OrganisationStore($this->env->database, $this->env->clock);

        foreach ([1, 2, 3] as $id) {
            $this->env->users->add($id, 'Synthetic User ' . $id, 'user' . $id . '@example.test');
        }

        $this->env->authorization->grant(1, Capability::MANAGE);
        $this->sources = new OrgCatalogueSourceRegistry();

        foreach (CoreOrganisationProviders::catalogueSources() as $source) {
            $this->sources->register($source);
        }

        $this->catalogue = $this->catalogueWith($this->sources);
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');

        foreach (['First Course' => 'first-course', 'Second Course' => 'second-course'] as $title => $slug) {
            $this->env->database->insert('courses', [
                'title' => $title,
                'slug' => $slug,
                'state' => 'published',
                'created_by' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** The free build registers the two sources the design names, and no more. */
    public function testTheFreeBuildRegistersTheCourseAndUnitSubtreeSources(): void
    {
        self::assertSame([CourseCatalogueSource::KEY, UnitSubtreeCatalogueSource::KEY], $this->sources->keys());

        $palette = $this->catalogue->sources($this->owner(), $this->organisation('acme'));
        self::assertCount(2, $palette);
        self::assertSame('course', $palette[0]['key']);
        self::assertSame('free', $palette[0]['edition']);
        self::assertSame(['course_id'], $palette[0]['params_schema']['required']);
        self::assertSame(['org_unit_id'], $palette[1]['params_schema']['required']);
    }

    /**
     * The rule the whole table exists for: an org administrator cannot widen
     * the set a site owner released.
     */
    public function testACourseNobodyReleasedIsRefusedWithForbiddenScope(): void
    {
        $org = $this->organisation('acme');

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessageMatches('/forbidden_scope/');
        $this->catalogue->add($this->owner(), $org, ['source_key' => 'course', 'course_id' => 1]);
    }

    public function testAReleasedCourseIsAcceptedAndResolvesToThatCourse(): void
    {
        $org = $this->organisation('acme');
        $this->release($org, 1);
        $entry = $this->catalogue->add($this->owner(), $org, [
            'source_key' => 'course',
            'course_id' => 1,
            'grant_mode' => 'auto_grant',
        ]);

        self::assertTrue($entry['resolved']);
        self::assertNull($entry['unresolved_reason']);
        self::assertSame([1], $entry['course_ids']);
        self::assertSame('auto_grant', $entry['grant_mode']);
        self::assertSame(1, $entry['params']['course_id'] ?? null);
    }

    /**
     * The re-check at read time. A release withdrawn after the entry was
     * written removes the course from the list without anybody editing the
     * entry, which is the whole reason the check is not only at save.
     */
    public function testAWithdrawnReleaseEmptiesTheEntryWithoutTouchingIt(): void
    {
        $org = $this->organisation('acme');
        $this->release($org, 1);
        $entry = $this->catalogue->add($this->owner(), $org, ['source_key' => 'course', 'course_id' => 1]);
        self::assertSame([1], $entry['course_ids']);

        $this->env->database->update('org_course_releases', ['state' => 'withdrawn'], ['organisation_id' => $org]);

        $entries = $this->catalogue->entries($this->owner(), $org);
        self::assertSame([], $entries[0]['course_ids']);
        self::assertSame([], $this->catalogue->courses($this->owner(), $org)['items']);
        $stored = $this->env->database->selectOne(Query::from('org_catalogue_entries'));
        self::assertIsArray($stored);
        self::assertSame(
            $entry['id'],
            (int) $stored['id'],
            'the entry itself is untouched: the release is what changed',
        );
    }

    /** A unit subtree rolls up the entries written against the units under it. */
    public function testTheUnitSubtreeSourceReachesTheCoursesOfEveryUnitBelowIt(): void
    {
        $org = $this->organisation('acme');
        $this->release($org, 1);
        $this->release($org, 2);
        $parent = $this->unit($org, 'North', null);
        $child = $this->unit($org, 'North West', $parent);
        $this->catalogue->add($this->owner(), $org, [
            'source_key' => 'course',
            'scope_type' => 'unit',
            'scope_id' => $parent,
            'course_id' => 1,
        ]);
        $this->catalogue->add($this->owner(), $org, [
            'source_key' => 'course',
            'scope_type' => 'unit',
            'scope_id' => $child,
            'course_id' => 2,
        ]);

        $entry = $this->catalogue->add($this->owner(), $org, [
            'source_key' => 'unit-subtree',
            'params' => ['org_unit_id' => $parent],
        ]);

        sort($entry['course_ids']);
        self::assertSame([1, 2], $entry['course_ids']);

        $narrowed = $this->catalogue->add($this->owner(), $org, [
            'source_key' => 'unit-subtree',
            'params' => ['org_unit_id' => $parent, 'include_descendants' => false],
        ]);
        self::assertSame([1], $narrowed['course_ids'], 'without descendants only the named unit is reached');
    }

    /** A parameter the source never published is a 422 naming the field. */
    public function testAParameterTheSourceNeverDeclaredIsRefusedByName(): void
    {
        $org = $this->organisation('acme');
        $unit = $this->unit($org, 'North', null);

        try {
            $this->catalogue->add($this->owner(), $org, [
                'source_key' => 'unit-subtree',
                'params' => ['org_unit_id' => $unit, 'include_descendents' => false],
            ]);
            self::fail('a mistyped parameter must be refused by name');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('params.include_descendents', $refusal->errors);
        }
    }

    /** A unit belonging to somebody else is not a unit this entry may name. */
    public function testAUnitOfAnotherOrganisationIsRefused(): void
    {
        $mine = $this->organisation('acme');
        $theirs = $this->organisation('globex');
        $foreign = $this->unit($theirs, 'Theirs', null);

        try {
            $this->catalogue->add($this->owner(), $mine, [
                'source_key' => 'unit-subtree',
                'params' => ['org_unit_id' => $foreign],
            ]);
            self::fail('a unit outside this organisation must be refused');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('params.org_unit_id', $refusal->errors);
        }
    }

    /**
     * The copy deck's orphaned-source line, proved rather than described.
     *
     * The entry is written while the source is installed and read back on a
     * build where it is not, which is exactly what uninstalling a paid package
     * does to a customer's site. The organisation, its members and its other
     * entries must all survive.
     */
    public function testAnEntryWhoseSourceIsNotInstalledGrantsNothingAndLeavesTheOrganisationIntact(): void
    {
        $org = $this->organisation('acme');
        $this->release($org, 1);
        $this->catalogue->add($this->owner(), $org, ['source_key' => 'course', 'course_id' => 1]);

        // The Business seat-pool source, written by a site that had it and read
        // by one that does not.
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $orphan = $this->env->database->insert('org_catalogue_entries', [
            'organisation_id' => $org,
            'scope_type' => 'organisation',
            'scope_id' => 0,
            'source_key' => 'seat-pool',
            'course_id' => null,
            'params' => json_encode(['_v' => 1, 'seat_pool_id' => 7], JSON_THROW_ON_ERROR),
            'params_version' => 1,
            'grant_mode' => 'auto_grant',
            'applies_to_descendants' => 1,
            'state' => 'active',
            'created_by' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $entries = $this->catalogue->entries($this->owner(), $org);
        self::assertCount(2, $entries);
        $listed = array_column($entries, null, 'id');

        self::assertTrue($listed[$entries[0]['id']]['resolved']);
        self::assertFalse($listed[$orphan]['resolved']);
        self::assertSame(ManageOrganisationCatalogue::UNRESOLVED_SOURCE, $listed[$orphan]['unresolved_reason']);
        self::assertSame([], $listed[$orphan]['course_ids'], 'an unresolved entry grants nothing');

        $courses = $this->catalogue->courses($this->owner(), $org);
        self::assertSame([1], array_column($courses['items'], 'course_id'));
        self::assertSame(
            [['entry_id' => $orphan, 'source_key' => 'seat-pool', 'reason' => ManageOrganisationCatalogue::UNRESOLVED_SOURCE]],
            $courses['unresolved_entries'],
            'the missing list is reported beside the list, so an incomplete list is never read as a complete one',
        );

        $organisation = $this->env->database->selectOne(Query::from('organisations')->where('id', '=', $org));
        self::assertIsArray($organisation);
        self::assertSame(
            'active',
            (string) $organisation['state'],
            'the organisation and its members are intact',
        );
    }

    /** A key no installed source answers is refused at save, which is a different fact. */
    public function testASourceKeyNoInstalledProviderAnswersIsRefusedAtSave(): void
    {
        $org = $this->organisation('acme');

        try {
            $this->catalogue->add($this->owner(), $org, ['source_key' => 'programme', 'params' => ['program_id' => 4]]);
            self::fail('an unregistered source key must be refused at save');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('source_key', $refusal->errors);
        }
    }

    /** Two entries reaching one course fold into one row, and auto_grant wins. */
    public function testTwoEntriesReachingOneCourseFoldIntoOneRowWithTheStrongerMode(): void
    {
        $org = $this->organisation('acme');
        $this->release($org, 1);
        $visible = $this->catalogue->add($this->owner(), $org, [
            'source_key' => 'course',
            'course_id' => 1,
            'grant_mode' => 'visible',
        ]);
        $unit = $this->unit($org, 'North', null);
        $auto = $this->catalogue->add($this->owner(), $org, [
            'source_key' => 'course',
            'scope_type' => 'unit',
            'scope_id' => $unit,
            'course_id' => 1,
            'grant_mode' => 'auto_grant',
        ]);

        $courses = $this->catalogue->courses($this->owner(), $org)['items'];
        self::assertCount(1, $courses);
        self::assertSame('auto_grant', $courses[0]['grant_mode']);
        self::assertSame('First Course', $courses[0]['title']);
        sort($courses[0]['entry_ids']);
        self::assertSame([$visible['id'], $auto['id']], $courses[0]['entry_ids']);
    }

    /** A retired entry reaches nothing, and it is retired rather than deleted. */
    public function testARetiredEntryReachesNothingAndSurvivesAsARow(): void
    {
        $org = $this->organisation('acme');
        $this->release($org, 1);
        $entry = $this->catalogue->add($this->owner(), $org, ['source_key' => 'course', 'course_id' => 1]);
        $retired = $this->catalogue->retire($this->owner(), $org, $entry['id']);

        self::assertSame('retired', $retired['state']);
        self::assertSame([], $retired['course_ids']);
        self::assertSame([], $this->catalogue->courses($this->owner(), $org)['items']);
        self::assertSame(1, $this->env->database->count(Query::from('org_catalogue_entries')));
    }

    /** A source emitting a course nobody released grants nothing, whatever it says. */
    public function testASourceThatNamesAnUnreleasedCourseEmitsNoGrant(): void
    {
        $org = $this->organisation('acme');
        $this->release($org, 1);
        $entry = $this->catalogue->add($this->owner(), $org, ['source_key' => 'course', 'course_id' => 1]);
        $row = $this->env->database->selectOne(Query::from('org_catalogue_entries')->where('id', '=', $entry['id']));
        self::assertIsArray($row);

        // The provider is swapped for one that claims the whole catalogue. The
        // release policy is what answers, so the claim buys it nothing.
        $greedy = new OrgCatalogueSourceRegistry();
        $greedy->register(new GreedyCatalogueSource());
        $grants = $this->catalogueWith($greedy)->resolve($this->scope($org), $row);

        self::assertSame([1], array_map(static fn (CourseGrant $g): int => $g->courseId, $grants));
    }

    /** The route surface is six, all under the tenancy prefix. */
    public function testTheCatalogueSurfaceIsSixRoutesUnderTheOrganisationsOwnPath(): void
    {
        $definitions = OrganisationCatalogueRoutes::definitions();
        self::assertCount(6, $definitions);

        foreach ($definitions as [$method, $path]) {
            self::assertStringContainsString('/manage/organisations/{id}/catalogue', $path);
            self::assertContains($method, ['GET', 'POST', 'PATCH', 'DELETE']);
        }
    }

    // ── Harness ──────────────────────────────────────────────────────────

    private function catalogueWith(OrgCatalogueSourceRegistry $sources): ManageOrganisationCatalogue
    {
        return new ManageOrganisationCatalogue(
            $this->resolver(),
            $this->store,
            new OrganisationLearningStore($this->env->database, $this->env->clock, $this->env->random),
            new CourseReleasePolicy($this->env->database, $this->env->clock),
            $sources,
            $this->env->clock,
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

    private function unit(int $organisationId, string $name, ?int $parentId): int
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $path = ($parentId === null ? '' : $parentId . '/') . $name;
        $unitId = $this->env->database->insert('org_units', [
            'organisation_id' => $organisationId,
            'parent_id' => $parentId,
            'name' => $name,
            'path' => $path,
            'path_digest' => hash('sha256', $organisationId . $path),
            'depth' => $parentId === null ? 0 : 1,
            'state' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->env->database->insert('org_unit_closure', [
            'organisation_id' => $organisationId,
            'ancestor_id' => $unitId,
            'descendant_id' => $unitId,
            'depth' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($parentId !== null) {
            $this->env->database->insert('org_unit_closure', [
                'organisation_id' => $organisationId,
                'ancestor_id' => $parentId,
                'descendant_id' => $unitId,
                'depth' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return $unitId;
    }
}
