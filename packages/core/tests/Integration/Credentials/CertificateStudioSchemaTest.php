<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Schema\ColumnType;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;

/** Neutral contract and data-adoption proof, not a live CMS upgrade claim. */
final class CertificateStudioSchemaTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../../../';

    public function testFreshSchemaHasOneDefaultAndOnePolicyPerCourse(): void
    {
        $schema = SchemaDefinition::load();
        foreach (['width', 'height'] as $dimension) {
            $column = $schema->table('certificate_template_pages')->column($dimension);
            self::assertSame(ColumnType::Decimal, $column->type);
            self::assertSame(7, $column->precision);
            self::assertSame(2, $column->scale);
        }
        $templates = $schema->table('certificate_templates');
        foreach (['published_snapshot', 'published_summary', 'provenance', 'render_provenance'] as $name) {
            self::assertSame(ColumnType::Json, $templates->column($name)->type);
            self::assertTrue($templates->column($name)->nullable);
        }
        self::assertTrue($templates->column('active_default_slot')->nullable);
        self::assertTrue($templates->column('published_name')->nullable);
        self::assertTrue(array_reduce($templates->indexes, static fn (bool $found, $index): bool => $found
            || ($index->unique && $index->columns === ['active_default_slot']), false));
        $policies = $schema->table('course_certificate_policies');
        self::assertSame(1, $policies->column('revision')->default);
        self::assertSame(1, $policies->column('enabled')->default);
        self::assertTrue($policies->column('template_id')->nullable);
        self::assertTrue(array_reduce($policies->indexes, static fn (bool $found, $index): bool => $found
            || ($index->unique && $index->columns === ['course_id']), false));
    }

    /**
     * Both install paths carry every publication column.
     *
     * This claim was made against the 1.11.0 migration on each host until R13
     * collapsed the history into one compiled install: the product had never
     * been released, so the migration described an upgrade nobody had run. The
     * claim itself did not change, only where it is read from. A column the
     * Joomla script creates and the WordPress schema forgets is still invisible
     * until the first write to it fails, on one host and nowhere else.
     */
    public function testBothInstallPathsCarryEveryPublicationColumn(): void
    {
        $joomla = $this->joomlaInstall();
        $wordpress = implode("\n", $this->wordpressSchema());
        $joomlaTemplates = $this->createBlock($joomla, 'certificate_templates');
        $wordpressTemplates = $this->createBlock($wordpress, 'certificate_templates');
        foreach (
            [
            'published_snapshot', 'published_summary', 'published_name', 'published_revision',
            'has_unpublished_changes', 'published_at', 'published_by', 'active_default_slot',
            'category', 'provenance', 'render_provenance',
            ] as $column
        ) {
            self::assertSame(1, substr_count($joomlaTemplates, '`' . $column . '` '), $column);
            self::assertSame(1, substr_count($wordpressTemplates, '`' . $column . '` '), $column);
        }
        self::assertStringContainsString('course_certificate_policies_course_id_uq', $joomla);
        self::assertStringContainsString('course_certificate_policies_course_id_uq', $wordpress);
        self::assertStringContainsString('certificate_templates_active_default_slot_uq', $joomla);
        self::assertStringContainsString('certificate_templates_active_default_slot_uq', $wordpress);
        self::assertDoesNotMatchRegularExpression('/\b(?:DELETE|DROP|UPDATE|INSERT)\b/i', $joomla);
        self::assertDoesNotMatchRegularExpression('/\b(?:DELETE|DROP|ALTER|INSERT|UPDATE)\b/i', $wordpress);
    }

    /**
     * The shape a fresh install creates enforces one active default, and
     * freezes nothing.
     *
     * The 1.11.0 migration used to adopt an existing site's templates into the
     * new publication model: pick the oldest published default, leave every
     * body byte alone, and freeze no snapshot, because SQL must never freeze a
     * graph outside its aggregate lock. Two of those three were statements
     * about rows a clean install does not have, and they went with the
     * migration. The third is a property of the shape and outlived it: the
     * unique slot is what makes "one active default" true, and it is true on a
     * site that never ran an adoption at all.
     *
     * The exercise is against the shipped shape rather than a hand-written one,
     * so a slot that stopped being unique fails here rather than on the first
     * site with two defaults.
     */
    public function testTheInstalledShapeAdmitsOneActiveDefaultAndFreezesNothing(): void
    {
        $templates = $this->createBlock($this->joomlaInstall(), 'certificate_templates');
        self::assertStringContainsString('`published_snapshot` JSON NULL', $templates);
        self::assertStringContainsString('`active_default_slot` VARCHAR(10) NULL', $templates);
        self::assertStringContainsString(
            'UNIQUE KEY `certificate_templates_active_default_slot_uq` (`active_default_slot`)',
            $templates,
        );

        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE vidyalms_certificate_templates (
            id INTEGER PRIMARY KEY, name TEXT, body TEXT, state TEXT, is_default INTEGER,
            published_snapshot TEXT, published_summary TEXT, published_name TEXT, published_revision INTEGER,
            has_unpublished_changes INTEGER DEFAULT 1, published_at TEXT, published_by INTEGER,
            active_default_slot TEXT UNIQUE, category TEXT, provenance TEXT, render_provenance TEXT
        )');
        $insert = $pdo->prepare(
            'INSERT INTO vidyalms_certificate_templates (id,name,body,state,active_default_slot) VALUES (?,?,?,?,?)'
        );
        $insert->execute([1, 'Site default', '<b>published bytes</b>', 'published', 'site']);
        $insert->execute([2, 'Another published', '<b>other bytes</b>', 'published', null]);

        $snapshot = $pdo->query('SELECT COUNT(*) FROM vidyalms_certificate_templates WHERE published_snapshot IS NOT NULL');
        self::assertNotFalse($snapshot);
        self::assertSame(0, (int) $snapshot->fetchColumn(), 'An install must never freeze a graph.');

        $selected = $pdo->query("SELECT id FROM vidyalms_certificate_templates WHERE active_default_slot='site'");
        self::assertNotFalse($selected);
        self::assertSame([1], $selected->fetchAll(\PDO::FETCH_COLUMN));

        $this->expectException(\PDOException::class);
        $pdo->exec("UPDATE vidyalms_certificate_templates SET active_default_slot='site' WHERE id=2");
    }

    private function joomlaInstall(): string
    {
        $sql = file_get_contents(
            self::ROOT . 'packages/adapter-joomla/com_vidyalms/admin/sql/install.mysql.utf8.sql'
        );
        self::assertIsString($sql);

        return (string) preg_replace('/^\s*--.*$/m', '', str_replace("\r\n", "\n", $sql));
    }

    /** @return list<string> */
    private function wordpressSchema(): array
    {
        $schema = require self::ROOT . 'packages/adapter-wordpress/vidyalms/schema/schema.php';
        self::assertIsArray($schema);
        $statements = [];
        foreach ($schema as $statement) {
            self::assertIsString($statement);
            $statements[] = $statement;
        }
        return $statements;
    }

    /**
     * One table's CREATE block, from either compiled artefact.
     *
     * Both flavours are accepted because the two hosts write the same table
     * under two different names: `#__vidyalms_x` on Joomla and
     * `{prefix}vidyalms_x` on WordPress.
     */
    private function createBlock(string $sql, string $table): string
    {
        $found = [];
        self::assertSame(
            1,
            preg_match(
                '/CREATE TABLE (?:IF NOT EXISTS )?`(?:#__|\{prefix\})vidyalms_' . $table . '` \([\s\S]*?\n\)[^;]*;/',
                $sql,
                $found,
            ),
            $table . ' is not created once by the install path',
        );

        return $found[0];
    }
}
