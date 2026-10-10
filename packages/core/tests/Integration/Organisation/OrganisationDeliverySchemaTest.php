<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;

/**
 * Every organisation and seat table the delivery stages added reaches both
 * hosts, and the entitlement columns that carry an organisation stay optional.
 *
 * The tables were listed by the release that created them, and each list was
 * checked against that release's own migration on each host. R13 collapsed the
 * history into one compiled install, because the product had never been
 * released and the migrations described upgrades nobody had run, so the lists
 * are now checked against the install. They are still lists rather than a walk
 * of the whole schema: a table quietly dropped is a failure here, which a walk
 * of whatever the schema happens to declare would never be.
 */
final class OrganisationDeliverySchemaTest extends TestCase
{
    public function testBothInstallPathsContainEveryOrganisationTableAndAccessColumn(): void
    {
        $root = __DIR__ . '/../../../../../';
        $schema = SchemaDefinition::load();

        // Grouped by the stage that added them, because the grouping is what
        // says which tables have to arrive together for a stage to work at all.
        $stages = [
            'the organisation spine' => ['organisations', 'org_memberships', 'org_role_assignments',
                'org_membership_events', 'invitations', 'org_invitation_claims', 'org_invitation_limits',
                'org_notification_secrets'],
            'the seat slice' => ['org_access_termination_jobs', 'org_learning_locks', 'org_course_releases',
                'seat_pools', 'seat_assignments', 'seat_events'],
            'the unit tree' => ['org_units', 'org_unit_closure'],
        ];

        $joomla = file_get_contents(
            $root . 'packages/adapter-joomla/com_vidyalms/admin/sql/install.mysql.utf8.sql'
        );
        self::assertIsString($joomla);

        /** @var mixed $statements */
        $statements = require $root . 'packages/adapter-wordpress/vidyalms/schema/schema.php';
        self::assertIsArray($statements);
        $wordpress = '';

        foreach ($statements as $statement) {
            self::assertIsString($statement);
            $wordpress .= $statement . ";\n";
        }

        foreach ($stages as $stage => $tables) {
            foreach ($tables as $table) {
                self::assertSame($table, $schema->table($table)->name, $stage);
                self::assertSame(
                    1,
                    substr_count($joomla, 'CREATE TABLE IF NOT EXISTS `#__vidyalms_' . $table . '`'),
                    $table . ' is not installed once on Joomla (' . $stage . ')',
                );
                self::assertSame(
                    1,
                    substr_count($wordpress, 'CREATE TABLE `{prefix}vidyalms_' . $table . '`'),
                    $table . ' is not installed once on WordPress (' . $stage . ')',
                );
            }
        }

        self::assertDoesNotMatchRegularExpression('/\b(?:DELETE|DROP)\b/i', $joomla);
        self::assertDoesNotMatchRegularExpression('/\b(?:DELETE|DROP|ALTER|INSERT)\b/i', $wordpress);

        // An entitlement can belong to an organisation and does not have to,
        // which is why every one of these is nullable: a site with no
        // organisations at all still writes entitlements.
        foreach (['organisation_id', 'org_membership_id', 'source_ref_digest', 'revoked_reason', 'revoked_ref'] as $column) {
            self::assertTrue($schema->table('entitlements')->column($column)->nullable);
        }

        $unique = array_filter(
            $schema->table('entitlements')->indexes,
            static fn ($index): bool => $index->unique && $index->columns === ['source_ref_digest']
        );
        self::assertCount(1, $unique);

        // The version the repository declares, and the floor it installs at.
        // Both are read rather than a literal, because the number moves and the
        // relationship does not.
        $version = require $root . 'tooling/schema/version.php';
        $floor = require $root . 'tooling/schema/floor.php';
        self::assertIsString($version);
        self::assertIsString($floor);
        self::assertGreaterThanOrEqual(0, version_compare($version, $floor));
    }
}
