<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;

/**
 * Schema 1.15.0: the two ledger payload versions, the seat ledger's append-once
 * key and the pool term sweep index.
 *
 * The point of landing these while both ledgers hold a handful of rows is that
 * none of them can be added later without either a backfill or a chain that
 * cannot be verified across the change. The tests below hold both halves: that
 * a fresh install and an upgrade describe the same database, and that a seat
 * event written before the columns existed still hashes to the value stored
 * beside it.
 */
final class OrganisationLedgerVersioningTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../../';

    public function testTheTwoLedgersCarryAPayloadVersionDefaultingToUnversioned(): void
    {
        $schema = SchemaDefinition::load();

        foreach (['org_membership_events', 'seat_events'] as $table) {
            $column = $schema->table($table)->column('payload_version');
            self::assertFalse($column->nullable, $table);
            self::assertTrue($column->hasDefault, $table);
            self::assertSame(StoredShape::UNVERSIONED, $column->default, $table);
        }
    }

    public function testTheSeatLedgerCarriesANullableIdempotencyKeyUnderAUniqueIndex(): void
    {
        $seatEvents = SchemaDefinition::load()->table('seat_events');

        self::assertTrue($seatEvents->column('idempotency_key')->nullable);
        self::assertSame(190, $seatEvents->column('idempotency_key')->length);

        $unique = array_filter(
            $seatEvents->indexes,
            static fn ($index): bool => $index->unique && $index->columns === ['idempotency_key']
        );

        self::assertCount(1, $unique);
        self::assertSame('seat_events_idem_uq', array_values($unique)[0]->name);
    }

    public function testTheTermSweepIndexExistsOnlyBecauseBothItsColumnsDo(): void
    {
        $seatPools = SchemaDefinition::load()->table('seat_pools');

        self::assertTrue($seatPools->hasColumn('state'));
        self::assertTrue($seatPools->hasColumn('ends_at'));
        self::assertCount(
            1,
            array_filter($seatPools->indexes, static fn ($index): bool => $index->columns === ['state', 'ends_at'])
        );

        // The reservation sweep's own index, which R3 could add because R3
        // added the column it covers. Both halves are asserted together for
        // the reason the pair above is: an index over a column that does not
        // exist is not a schema, and a column with no index is a sweep that
        // scans every assignment on the site once an hour.
        $assignments = SchemaDefinition::load()->table('seat_assignments');

        self::assertTrue($assignments->hasColumn('reserved_until'));
        self::assertTrue($assignments->column('reserved_until')->nullable);
        self::assertCount(
            1,
            array_filter($assignments->indexes, static fn ($index): bool => $index->columns === ['reserved_until'])
        );

        // A seat reserved at issue has no membership and may have no account,
        // because a pending invitation to somebody with no account cannot be a
        // membership row. Both columns were widened rather than sentinelled.
        self::assertTrue($assignments->column('membership_id')->nullable);
        self::assertTrue($assignments->column('user_id')->nullable);
    }

    /**
     * Both hosts install the ledger versioning shape, and neither install
     * writes a row.
     *
     * This was asserted against the 1.15.0 pair until R13 collapsed the
     * history: the product had never been released, so the two files described
     * an upgrade nobody ran. The claim moved to the artefacts a site is built
     * from, which is where the shape now comes from, and the additive
     * assertion moved with it: an install has no business altering anything.
     */
    public function testBothHostsInstallTheLedgerVersioningShapeAndWriteNothing(): void
    {
        $sql = file_get_contents(
            self::ROOT . 'packages/adapter-joomla/com_vidyalms/admin/sql/install.mysql.utf8.sql'
        );

        self::assertIsString($sql);
        self::assertStringContainsString('`#__vidyalms_org_membership_events`', $sql);
        self::assertStringContainsString('`payload_version` INT UNSIGNED NOT NULL DEFAULT 0', $sql);
        self::assertStringContainsString('`idempotency_key` VARCHAR(190) NULL', $sql);
        self::assertStringContainsString('UNIQUE KEY `seat_events_idem_uq` (`idempotency_key`)', $sql);
        self::assertStringContainsString('KEY `seat_pools_state_ends_at_ix` (`state`, `ends_at`)', $sql);
        self::assertDoesNotMatchRegularExpression('/\b(?:DELETE|DROP|TRUNCATE|MODIFY|CHANGE|ALTER)\b/i', $sql);

        /** @var mixed $statements */
        $statements = require self::ROOT . 'packages/adapter-wordpress/vidyalms/schema/schema.php';
        self::assertIsArray($statements);
        $wordpress = '';

        foreach ($statements as $statement) {
            self::assertIsString($statement);
            $wordpress .= $statement . ";\n";
        }

        self::assertStringContainsString('{prefix}vidyalms_org_membership_events', $wordpress);
        self::assertStringContainsString('{prefix}vidyalms_seat_events', $wordpress);
        self::assertStringContainsString('{prefix}vidyalms_seat_pools', $wordpress);
        self::assertStringContainsString('`payload_version` INT UNSIGNED NOT NULL DEFAULT 0', $wordpress);
        self::assertStringContainsString('UNIQUE KEY `seat_events_idem_uq` (`idempotency_key`)', $wordpress);
        self::assertDoesNotMatchRegularExpression('/\b(?:DELETE|DROP|ALTER|INSERT|UPDATE)\b/i', $wordpress);
    }

    /**
     * The schema version is declared once, reached by every host, and never
     * below the floor the first release installed at.
     *
     * The invariant used to be that both hosts carried a migration for whatever
     * the version said. There are no migrations now: every site installs at
     * tooling/schema/floor.php and the version has to be a number an install or
     * an upgrade can genuinely have reached. What has not changed is that a
     * literal is never repeated here, because a fourth copy of the number is a
     * fourth thing to remember and the next bump would fail this test for being
     * a bump rather than for missing a host.
     */
    public function testTheSchemaVersionIsDeclaredOnceAndReachedByBothHosts(): void
    {
        $version = require self::ROOT . 'tooling/schema/version.php';
        $floor = require self::ROOT . 'tooling/schema/floor.php';
        self::assertIsString($version);
        self::assertIsString($floor);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $version);
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $floor);
        self::assertGreaterThanOrEqual(
            0,
            version_compare($version, $floor),
            'The repository declares a version below the floor, which no shipped site can be at.',
        );

        $activator = file_get_contents(
            self::ROOT . 'packages/adapter-wordpress/vidyalms/src/Setup/Activator.php'
        );
        self::assertIsString($activator);
        self::assertStringContainsString("public const SCHEMA_VERSION = '" . $version . "';", $activator);

        // Joomla records the version from the highest name in its update
        // folder, so the folder holds a file for the declared version and
        // nothing above it. At the floor that file is a marker with no
        // statements in it, and it is what makes a fresh install record a
        // version at all.
        $updates = self::ROOT . 'packages/adapter-joomla/com_vidyalms/admin/sql/updates/mysql/';
        self::assertFileExists($updates . $version . '.sql');

        foreach ((array) glob($updates . '*.sql') as $path) {
            $shipped = pathinfo((string) $path, PATHINFO_FILENAME);

            self::assertLessThanOrEqual(
                0,
                version_compare($shipped, $version),
                $shipped . ' is above the version the repository declares.',
            );
            self::assertGreaterThanOrEqual(
                0,
                version_compare($shipped, $floor),
                $shipped . ' is below the floor and no site would run it.',
            );
        }
    }

    public function testAFreshInstallAndAnUpgradeDescribeTheSameColumnsAndIndexes(): void
    {
        $install = file_get_contents(
            self::ROOT . 'packages/adapter-joomla/com_vidyalms/admin/sql/install.mysql.utf8.sql'
        );
        $wordpress = file_get_contents(self::ROOT . 'packages/adapter-wordpress/vidyalms/schema/schema.php');

        self::assertIsString($install);
        self::assertIsString($wordpress);

        foreach ([$install, $wordpress] as $generated) {
            self::assertSame(2, substr_count($generated, '`payload_version` INT UNSIGNED NOT NULL DEFAULT 0'));
            self::assertStringContainsString('`idempotency_key` VARCHAR(190) NULL', $generated);
            self::assertStringContainsString('UNIQUE KEY `seat_events_idem_uq` (`idempotency_key`)', $generated);
            self::assertStringContainsString('KEY `seat_pools_state_ends_at_ix` (`state`, `ends_at`)', $generated);
        }
    }

    public function testAnUnversionedSeatEventStillHashesToWhatWasStoredBesideIt(): void
    {
        $legacy = [
            'organisation_id' => 1, 'pool_id' => 2, 'assignment_id' => null, 'event_key' => 'seat_assigned',
            'seat_delta' => -1, 'balance_after' => 4, 'subject_user_id' => 3, 'entitlement_id' => 9,
            'actor_id' => 1, 'reason_key' => 'member_assignment', 'occurred_at' => '2026-09-01 10:00:00',
            'recorded_at' => '2026-09-01 10:00:00', 'organisation_revision' => 7,
            'prev_hash' => str_repeat('0', 64), 'payload' => '{}',
        ];

        $before = OrganisationLearningStore::digest($legacy);

        // The same row read back after the migration, which is how every row
        // already in the ledger arrives from now on.
        $migrated = $legacy + ['payload_version' => StoredShape::UNVERSIONED, 'idempotency_key' => null];

        self::assertSame($before, OrganisationLearningStore::digest($migrated));
    }

    public function testAVersionedSeatEventBindsItsVersionIntoTheChain(): void
    {
        $row = [
            'organisation_id' => 1, 'pool_id' => 2, 'assignment_id' => 5, 'event_key' => 'seat_assigned',
            'seat_delta' => -1, 'balance_after' => 4, 'subject_user_id' => 3, 'entitlement_id' => 9,
            'actor_id' => 1, 'reason_key' => 'member_assignment', 'occurred_at' => '2026-09-01 10:00:00',
            'recorded_at' => '2026-09-01 10:00:00', 'organisation_revision' => 7,
            'prev_hash' => str_repeat('0', 64), 'payload' => '{}',
        ];

        $unversioned = OrganisationLearningStore::digest($row + ['payload_version' => StoredShape::UNVERSIONED]);
        $versioned = OrganisationLearningStore::digest($row + ['payload_version' => 1]);
        $next = OrganisationLearningStore::digest($row + ['payload_version' => 2]);

        self::assertNotSame($unversioned, $versioned);
        self::assertNotSame($versioned, $next);

        // A claimed row binds its key; an unclaimed one hashes as it always did.
        self::assertSame(
            $versioned,
            OrganisationLearningStore::digest($row + ['payload_version' => 1, 'idempotency_key' => null])
        );
        self::assertNotSame(
            $versioned,
            OrganisationLearningStore::digest($row + ['payload_version' => 1, 'idempotency_key' => 'claim-1'])
        );
    }

    public function testBothStoresDeclareTheVersionTheyWrite(): void
    {
        self::assertSame(1, OrganisationLearningStore::PAYLOAD_VERSION);
        self::assertSame(1, OrganisationStore::PAYLOAD_VERSION);
        self::assertNotSame(StoredShape::UNVERSIONED, OrganisationLearningStore::PAYLOAD_VERSION);
    }
}
