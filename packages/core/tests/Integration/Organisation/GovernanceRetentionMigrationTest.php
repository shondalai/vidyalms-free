<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;

/**
 * The governance half: per-organisation retention, the four compliance tables
 * and the ledger redaction receipt, against the install that creates them.
 *
 * The rule this suite holds is the one a two-host product breaks silently: two
 * sites have to describe the same database, and nothing but a comparison
 * proves it. The two sites used to be one that upgraded through 1.23.0 and one
 * that installed clean. R13 removed the first: the product had never been
 * released, so the migration described an upgrade nobody had run, and the
 * history collapsed into one compiled install.
 *
 * The two sites are now a Joomla one and a WordPress one, and each assertion
 * that named the migration now names the artefact the host installs from. The
 * property is unchanged and the comparison is one hop closer to the authority
 * both artefacts are compiled from, packages/core/schema/tables.php.
 */
final class GovernanceRetentionMigrationTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../../';
    private const JOOMLA_INSTALL = 'packages/adapter-joomla/com_vidyalms/admin/sql/install.mysql.utf8.sql';
    private const WORDPRESS_SCHEMA = 'packages/adapter-wordpress/vidyalms/schema/schema.php';

    /** The five tables the governance stage added, none of which existed before it. */
    private const NEW_TABLES = [
        'compliance_requirements',
        'compliance_assignments',
        'compliance_cycles',
        'assignment_exceptions',
        'org_ledger_redactions',
    ];

    public function testEveryNewTableIsDeclaredOnceAndCreatedByTheJoomlaInstall(): void
    {
        $schema = SchemaDefinition::load();
        $sql = $this->joomla();

        foreach (self::NEW_TABLES as $table) {
            self::assertSame($table, $schema->table($table)->name, $table . ' is declared in tables.php');
            self::assertSame(
                1,
                substr_count($sql, 'CREATE TABLE IF NOT EXISTS `#__vidyalms_' . $table . '`'),
                $table . ' is created exactly once by the install',
            );
        }
    }

    public function testBothHostsCreateEveryColumnTheNeutralSchemaDeclares(): void
    {
        $schema = SchemaDefinition::load();
        $joomla = $this->joomla();
        $wordpress = $this->wordpress();

        foreach (self::NEW_TABLES as $table) {
            foreach ($schema->table($table)->columns as $column) {
                self::assertStringContainsString(
                    '`' . $column->name . '`',
                    $joomla,
                    $table . '.' . $column->name . ' is missing from the Joomla install',
                );
                self::assertStringContainsString(
                    '`' . $column->name . '`',
                    $wordpress,
                    $table . '.' . $column->name . ' is missing from the WordPress schema',
                );
            }
        }

        // The two columns on a table every site already holds rows in.
        foreach (['retention_policy', 'retention_policy_version'] as $column) {
            self::assertStringContainsString('`' . $column . '`', $joomla);
            self::assertStringContainsString('`' . $column . '`', $wordpress);
        }
    }

    public function testTheRetentionColumnsAreNullableSoNoStoredOrganisationChanges(): void
    {
        $schema = SchemaDefinition::load();
        $columns = [];

        foreach ($schema->table('organisations')->columns as $column) {
            $columns[$column->name] = $column;
        }

        // A policy written onto a row would be the product deciding a
        // controller question on a controller's behalf, so the column is
        // nullable with no default and an organisation with none reads back as
        // the organisation it already was.
        self::assertTrue($columns['retention_policy']->nullable);
        self::assertStringContainsString('`retention_policy` JSON NULL', $this->joomla());
        self::assertStringContainsString('`retention_policy` JSON NULL', $this->wordpress());
    }

    public function testTheReceiptCarriesNoUpdatedAtBecauseNothingMayUpdateIt(): void
    {
        $schema = SchemaDefinition::load();
        $names = array_map(
            static fn (object $column): string => (string) $column->name,
            $schema->table('org_ledger_redactions')->columns,
        );

        // The row carries its own redacted_at, and a receipt for an append-only
        // operation that also carried an updated_at would advertise a write
        // nothing may make. The same reasoning admin_access_log states.
        self::assertContains('redacted_at', $names);
        self::assertNotContains('updated_at', $names);
        self::assertNotContains('created_at', $names);
        // And it holds no user id at all: the subject is a keyed token.
        self::assertNotContains('user_id', $names);
        self::assertNotContains('subject_user_id', $names);
        self::assertContains('subject_token', $names);
    }

    public function testTheInstallIsAdditiveOnlyOnBothHosts(): void
    {
        $sql = $this->joomla();

        self::assertDoesNotMatchRegularExpression('/\b(?:DELETE|DROP|TRUNCATE)\b/i', $sql);
        // No destructive ALTER against the ledgers or the audit log, which is
        // the rule this area was given rather than one it chose: the receipt is
        // a separate table precisely so seat_events is never altered.
        self::assertStringNotContainsString('ALTER TABLE `#__vidyalms_seat_events`', $sql);
        self::assertStringNotContainsString('ALTER TABLE `#__vidyalms_admin_access_log`', $sql);
        self::assertStringNotContainsString('ALTER TABLE', $sql);

        $wordpress = $this->wordpress();

        self::assertDoesNotMatchRegularExpression('/\b(?:DELETE|DROP|ALTER|INSERT|UPDATE)\b/i', $wordpress);
    }

    public function testTheWordPressSchemaCarriesEveryNewTableAndTheRetentionPair(): void
    {
        $wordpress = $this->wordpress();

        foreach (self::NEW_TABLES as $table) {
            self::assertStringContainsString('{prefix}vidyalms_' . $table . '`', $wordpress, $table);
        }

        // dbDelta adds a missing column and says nothing when it silently does
        // neither, so the two hosts agreeing about the pair is what is left to
        // check once the shape check that used to follow dbDelta has gone.
        self::assertStringContainsString('`retention_policy` JSON NULL', $wordpress);
        self::assertStringContainsString('`retention_policy_version` INT UNSIGNED NOT NULL DEFAULT 1', $wordpress);
        self::assertStringContainsString('`retention_policy_version` INT UNSIGNED NOT NULL DEFAULT 1', $this->joomla());
    }

    private function joomla(): string
    {
        $sql = file_get_contents(self::ROOT . self::JOOMLA_INSTALL);
        self::assertIsString($sql);

        return (string) preg_replace('/^\s*--.*$/m', '', str_replace("\r\n", "\n", $sql));
    }

    private function wordpress(): string
    {
        /** @var mixed $statements */
        $statements = require self::ROOT . self::WORDPRESS_SCHEMA;
        self::assertIsArray($statements);
        $out = '';

        foreach ($statements as $statement) {
            self::assertIsString($statement);
            $out .= $statement . ";\n";
        }

        return $out;
    }
}
