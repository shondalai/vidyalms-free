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
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrgScopedReader;
use Shondalai\VidyaLMS\Core\Provider\Organisation\CoreOrganisationProviders;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The reader that makes the tenancy predicate a guarantee instead of a habit.
 */
final class OrgScopedReaderTest extends TestCase
{
    private const GATE = __DIR__ . '/../../../../../tooling/ci/gate.php';

    private TestPorts $env;
    private OrganisationStore $store;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->store = new OrganisationStore($this->env->database, $this->env->clock);

        foreach ([1, 2, 3] as $id) {
            $this->env->users->add($id, 'Synthetic User ' . $id, 'user' . $id . '@example.test');
        }

        $this->env->authorization->grant(1, Capability::MANAGE);
    }

    public function testEveryScopedTableIsARealTableAndNamesARealPredicateColumn(): void
    {
        $schema = SchemaDefinition::load();
        $names = $schema->tableNames();

        foreach (OrgScopedReader::SCOPED_TABLES as $table => $column) {
            self::assertContains($table, $names, $table);
            self::assertTrue($schema->table($table)->hasColumn($column), $table . '.' . $column);
        }
    }

    /**
     * The two lists were maintained by hand, and org_notification_secrets was
     * on neither: an organisation-owned table holding a sealed invitation
     * payload with a recipient address and an invitation secret in it, whose
     * unanchored lookup the gate therefore did not watch at all. A hand-kept
     * list is only as good as the last person to remember it, so the
     * completeness rule is derived from the schema instead.
     */
    public function testEveryOrganisationTableIsEitherScopedOrOnTheNamedExclusionList(): void
    {
        $schema = SchemaDefinition::load();
        $unlisted = [];

        foreach ($schema->tableNames() as $table) {
            $organisationOwned = str_starts_with($table, 'org_')
                || $schema->table($table)->hasColumn('organisation_id');

            if (!$organisationOwned) {
                continue;
            }

            if (
                isset(OrgScopedReader::SCOPED_TABLES[$table])
                || in_array($table, OrgScopedReader::UNSCOPED_ORGANISATION_TABLES, true)
            ) {
                continue;
            }

            $unlisted[] = $table;
        }

        self::assertSame([], $unlisted, 'each of these carries an organisation and is on neither list');

        // The exclusion list may not name a table that does not exist, or it
        // becomes a place to park a name and forget it.
        foreach (OrgScopedReader::UNSCOPED_ORGANISATION_TABLES as $excluded) {
            self::assertContains($excluded, $schema->tableNames(), $excluded);
        }
    }

    /**
     * The same completeness rule over every package's own schema, not the
     * core's alone.
     *
     * The rule read `packages/core/schema/tables.php` and the gate mirrored the
     * core constant, so an organisation-owned table declared by a package
     * outside the core was on neither list, could not be put on either without
     * a core edit, and the gate did not watch reads of it. `cohorts` has
     * carried an `organisation_id` since the organisation slice and nothing
     * anywhere knew. A package now declares its own tenancy beside its own
     * schema in `schema/org-tenancy.php`, and this walks every one of them.
     */
    public function testEveryPackagesOwnOrganisationTablesAreDeclaredBesideItsSchema(): void
    {
        $packages = glob(__DIR__ . '/../../../../*/schema/tables.php') ?: [];
        $unlisted = [];
        $checked = 0;

        foreach ($packages as $file) {
            if (realpath($file) === realpath(__DIR__ . '/../../../schema/tables.php')) {
                // The core's own schema is the case above, which reads the
                // core lists directly.
                continue;
            }

            $checked++;
            $schema = SchemaDefinition::load($file);
            $declaration = self::tenancyDeclarationFor($file);

            foreach ($schema->tableNames() as $table) {
                if (!str_starts_with($table, 'org_') && !$schema->table($table)->hasColumn('organisation_id')) {
                    continue;
                }

                if (
                    isset(OrgScopedReader::SCOPED_TABLES[$table])
                    || in_array($table, OrgScopedReader::UNSCOPED_ORGANISATION_TABLES, true)
                    || isset($declaration['scoped'][$table])
                    || isset($declaration['unscoped'][$table])
                ) {
                    continue;
                }

                $unlisted[] = basename(\dirname($file, 2)) . ':' . $table;
            }

            // A declaration may not name a table that package does not have.
            foreach ([...array_keys($declaration['scoped']), ...array_keys($declaration['unscoped'])] as $named) {
                self::assertContains($named, $schema->tableNames(), $named);
            }

            foreach ($declaration['scoped'] as $table => $column) {
                self::assertTrue($schema->table($table)->hasColumn($column), $table . '.' . $column);
            }
        }

        if ($checked === 0 && !is_dir(__DIR__ . '/../../../../pro-core')) {
            // The public free source carries the core alone. In the
            // development monorepo pro-core is present and has to be found.
            self::markTestSkipped('No package beside the core declares a schema in this checkout.');
        }

        self::assertGreaterThan(0, $checked, 'at least one package outside the core declares a schema');
        self::assertSame([], $unlisted, 'each of these carries an organisation and is declared nowhere');
    }

    /**
     * @return array{scoped: array<string, string>, unscoped: array<string, string>}
     */
    private static function tenancyDeclarationFor(string $schemaFile): array
    {
        $path = \dirname($schemaFile) . '/org-tenancy.php';

        if (!is_file($path)) {
            return ['scoped' => [], 'unscoped' => []];
        }

        /** @var mixed $declaration */
        $declaration = require $path;

        self::assertIsArray($declaration);

        /** @var array<string, string> $scoped */
        $scoped = \is_array($declaration['scoped'] ?? null) ? $declaration['scoped'] : [];
        /** @var array<string, string> $unscoped */
        $unscoped = \is_array($declaration['unscoped'] ?? null) ? $declaration['unscoped'] : [];

        return ['scoped' => $scoped, 'unscoped' => $unscoped];
    }

    public function testTheGateWatchesExactlyTheTablesTheReaderOwns(): void
    {
        // The two lists are in different packages and cannot import each
        // other, so the mirror is asserted rather than assumed: a table added
        // to one and not the other is a table the build stops watching.
        $gate = file_get_contents(self::GATE);
        self::assertIsString($gate);
        $start = strpos($gate, 'const ORG_SCOPED_TABLES = [');
        self::assertIsInt($start);
        $end = strpos($gate, '];', $start);
        self::assertIsInt($end);

        $names = [];
        preg_match_all("/'([a-z_]+)'/", substr($gate, $start, $end - $start), $names);
        $watched = $names[1];
        self::assertNotSame([], $watched);
        sort($watched);
        $owned = array_keys(OrgScopedReader::SCOPED_TABLES);
        sort($owned);

        self::assertSame($owned, $watched);
    }

    public function testTheReaderNarrowsOnTheOrganisationWithoutTheCallerSayingSo(): void
    {
        [$first, $second] = $this->twoOrganisations();

        $this->store->addMember($first, 2, 'org_member', 'manual', null);
        $this->store->addMember($second, 3, 'org_member', 'manual', null);

        $rows = $this->env->database->selectAll(OrgScopedReader::from($first, 'org_memberships'));
        self::assertCount(1, $rows);
        self::assertSame(2, (int) $rows[0]['user_id']);

        $others = $this->env->database->selectAll(OrgScopedReader::from($second, 'org_memberships'));
        self::assertCount(1, $others);
        self::assertSame(3, (int) $others[0]['user_id']);
    }

    public function testTheOrganisationRowItselfIsNarrowedOnItsOwnPrimaryKey(): void
    {
        [$first, $second] = $this->twoOrganisations();

        $row = $this->env->database->selectOne(OrgScopedReader::from($first, 'organisations'));
        self::assertIsArray($row);
        self::assertSame($first->organisationId, (int) $row['id']);
        self::assertNotSame($second->organisationId, (int) $row['id']);
    }

    public function testAnAliasedReadQualifiesThePredicateWithTheAlias(): void
    {
        [$first] = $this->twoOrganisations();
        $conditions = OrgScopedReader::from($first, 'org_memberships', 'm')->conditions();

        self::assertSame('m.organisation_id', $conditions[0]['column']);
    }

    public function testATableThatIsNotOrganisationScopedIsRefusedRatherThanSilentlyUnnarrowed(): void
    {
        [$first] = $this->twoOrganisations();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('is not organisation scoped');

        OrgScopedReader::from($first, 'courses');
    }

    /** @return array{0: OrgScope, 1: OrgScope} */
    private function twoOrganisations(): array
    {
        $registry = new OrgRoleRegistry();

        foreach (CoreOrganisationProviders::roles() as $role) {
            $registry->register($role);
        }

        $resolver = new OrgScopeResolver(new AccessGuard($this->env->authorization), $this->store, $registry);
        $scopes = [];

        foreach ([1, 2] as $index) {
            $id = $this->store->create([
                'name' => 'Organisation ' . $index,
                'slug' => 'org-' . $index,
                'created_by' => 1,
            ]);
            $scopes[] = OrgScope::fromResolver($resolver, Principal::user(1), $id, 'people');
        }

        return [$scopes[0], $scopes[1]];
    }
}
