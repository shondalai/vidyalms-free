<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;

/**
 * The admin access log and the ten membership columns, against the install
 * that creates them.
 *
 * The rule this suite holds is the one a two-host product breaks silently: two
 * sites have to describe the same database, and nothing but a comparison
 * proves it. Until R13 the two sites were one that upgraded through 1.16.0 and
 * one that installed clean. There is no upgraded site any more: the product had
 * never been released when the history was collapsed into one compiled
 * install, so the migration described a path nobody had walked and it is gone.
 *
 * The two sites are now a Joomla one and a WordPress one, and the comparison is
 * the stronger of the two rather than a weaker substitute: it compares the two
 * artefacts a site is actually built from, both generated from
 * packages/core/schema/tables.php by different compilers, and it fails on a
 * default typed differently, an index named differently or a column one
 * character wider.
 */
final class GovernanceMigrationTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../../';
    private const JOOMLA_INSTALL = 'packages/adapter-joomla/com_vidyalms/admin/sql/install.mysql.utf8.sql';
    private const WORDPRESS_SCHEMA = 'packages/adapter-wordpress/vidyalms/schema/schema.php';

    /**
     * The ten membership columns the governance stage added.
     *
     * They were read out of the migration's own ADD clauses until the migration
     * went. Naming them is what keeps the check from becoming a comparison of
     * the install against itself: a column silently dropped from the schema is a
     * failure here rather than a quietly shorter loop.
     *
     * @var list<string>
     */
    private const MEMBERSHIP_COLUMNS = [
        'is_primary',
        'begins_at',
        'ends_at',
        'job_title',
        'department_label',
        'division',
        'organization_label',
        'cost_centre',
        'employee_number',
        'manager_user_id',
    ];

    public function testBothHostsCreateTheSameAccessLog(): void
    {
        $joomla = self::createTable(self::read(self::JOOMLA_INSTALL), 'admin_access_log');
        $wordpress = self::createTable(self::wordpress(), 'admin_access_log');

        self::assertNotSame('', $joomla);
        self::assertNotSame('', $wordpress);
        self::assertSame(self::body($joomla), self::body($wordpress));
    }

    public function testEveryMembershipColumnIsDeclaredOnceOnBothHosts(): void
    {
        $joomla = self::createTable(self::read(self::JOOMLA_INSTALL), 'org_memberships');
        $wordpress = self::createTable(self::wordpress(), 'org_memberships');
        $schema = SchemaDefinition::load()->table('org_memberships');

        self::assertCount(10, self::MEMBERSHIP_COLUMNS);

        foreach (self::MEMBERSHIP_COLUMNS as $column) {
            self::assertTrue($schema->hasColumn($column), $column . ' left the neutral schema');
            self::assertSame(1, substr_count($joomla, '`' . $column . '` '), $column . ' on Joomla');
            self::assertSame(
                self::columnLine($joomla, $column),
                self::columnLine($wordpress, $column),
                $column . ' is declared differently on the two hosts',
            );
        }
    }

    public function testTheInstallIsAdditiveOnlyAndNoMembershipColumnIsMandatory(): void
    {
        $install = self::read(self::JOOMLA_INSTALL);
        $statements = (string) preg_replace('/^\s*--.*$/m', '', $install);

        // No shipped column changes type, no shipped index is dropped and no
        // row is rewritten, which is what an install script has no business
        // doing at all now that it is the only path onto a site.
        self::assertDoesNotMatchRegularExpression(
            '/\b(?:DELETE|DROP|TRUNCATE|MODIFY|CHANGE|UPDATE|INSERT)\b/i',
            $statements,
        );

        // Every one of the ten is nullable or defaulted. It mattered because a
        // membership row already existed when they arrived; it still matters,
        // because a mandatory job title would refuse the first membership a
        // site creates through any path that does not know about it.
        $joomla = self::createTable($install, 'org_memberships');

        foreach (self::MEMBERSHIP_COLUMNS as $column) {
            $line = self::columnLine($joomla, $column);

            self::assertMatchesRegularExpression('/(?:NULL|DEFAULT)/', $line, $line);
            self::assertTrue(
                !str_contains($line, ' NOT NULL') || str_contains($line, ' DEFAULT '),
                $line . ' is mandatory with no default',
            );
        }
    }

    public function testBothHostsCarryEveryGovernanceColumnTheStageAdded(): void
    {
        $joomla = self::read(self::JOOMLA_INSTALL);
        $wordpress = self::wordpress();

        foreach ([$joomla, $wordpress] as $generated) {
            self::assertStringContainsString('vidyalms_admin_access_log', $generated);
            self::assertStringContainsString('vidyalms_org_memberships', $generated);

            foreach (
                ['prev_hash', 'hash', 'scope_digest', 'ip_hash', 'request_ref', 'actor_org_id',
                    'subject_org_id'] as $column
            ) {
                self::assertStringContainsString('`' . $column . '`', $generated, $column);
            }

            foreach (self::MEMBERSHIP_COLUMNS as $column) {
                self::assertStringContainsString('`' . $column . '`', $generated, $column);
            }
        }

        // dbDelta is handed the WordPress artefact verbatim, so a statement
        // that wrote anything would make an activation a write against
        // somebody's data.
        self::assertDoesNotMatchRegularExpression('/\b(?:DELETE|DROP|ALTER|INSERT|UPDATE)\b/i', $wordpress);
    }

    public function testTheGeneratedWordPressSchemaCarriesTheSameTable(): void
    {
        $schema = self::read(self::WORDPRESS_SCHEMA);

        self::assertStringContainsString('vidyalms_admin_access_log', $schema);
        self::assertStringContainsString('`manager_user_id` INT UNSIGNED NULL', $schema);
        self::assertStringContainsString(
            'KEY `org_memberships_organisation_id_manager_user_id_ix` (`organisation_id`, `manager_user_id`)',
            $schema
        );
    }

    public function testTheAccessLogHasNoUpdatedAtBecauseNothingMayUpdateIt(): void
    {
        $table = SchemaDefinition::load()->table('admin_access_log');

        self::assertFalse($table->hasColumn('updated_at'));
        self::assertStringNotContainsString(
            '`updated_at`',
            self::createTable(self::read(self::JOOMLA_INSTALL), 'admin_access_log')
        );
        self::assertStringNotContainsString(
            '`updated_at`',
            self::createTable(self::wordpress(), 'admin_access_log')
        );
    }

    /** The compiled WordPress schema as one script, so the same readers serve both hosts. */
    private static function wordpress(): string
    {
        /** @var mixed $statements */
        $statements = require self::ROOT . self::WORDPRESS_SCHEMA;
        self::assertIsArray($statements);
        $script = '';

        foreach ($statements as $statement) {
            self::assertIsString($statement);
            $script .= $statement . ";\n";
        }

        return $script;
    }

    private static function createTable(string $sql, string $table): string
    {
        $found = [];
        $pattern = '/CREATE TABLE (?:IF NOT EXISTS )?`(?:#__|\{prefix\})vidyalms_'
            . preg_quote($table, '/') . '` \([\s\S]*?\n\)[^;]*;/';

        if (preg_match($pattern, $sql, $found) !== 1) {
            return '';
        }

        return $found[0];
    }

    /** A CREATE block without its header, so two hosts can be compared. */
    private static function body(string $block): string
    {
        $opens = strpos($block, "(\n");
        self::assertNotFalse($opens);

        return substr($block, $opens);
    }

    private static function columnLine(string $block, string $column): string
    {
        $found = [];
        self::assertSame(
            1,
            preg_match('/^\s*(`' . preg_quote($column, '/') . '` [^\n]*?),?$/m', $block, $found),
            $column . ' has no declaration in the block',
        );

        $line = trim((string) ($found[1] ?? ''));
        self::assertNotSame('', $line, $column . ' matched an empty declaration');

        return $line;
    }

    private static function read(string $relative): string
    {
        $contents = file_get_contents(self::ROOT . $relative);
        self::assertIsString($contents, $relative);

        return str_replace("\r\n", "\n", $contents);
    }
}
