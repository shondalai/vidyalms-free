<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Schema\ColumnType;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;

final class NotificationTemplateSchemaTest extends TestCase
{
    private const JOOMLA_INSTALL =
        __DIR__ . '/../../../../../packages/adapter-joomla/com_vidyalms/admin/sql/install.mysql.utf8.sql';

    private const WORDPRESS_SCHEMA =
        __DIR__ . '/../../../../../packages/adapter-wordpress/vidyalms/schema/schema.php';

    private const WORDPRESS_ACTIVATOR =
        __DIR__ . '/../../../../../packages/adapter-wordpress/vidyalms/src/Setup/Activator.php';

    private const SCHEMA_VERSION = __DIR__ . '/../../../../../tooling/schema/version.php';

    private const SCHEMA_FLOOR = __DIR__ . '/../../../../../tooling/schema/floor.php';

    /** @var array<string, array{type: ColumnType, nullable: bool, default: int|null}> */
    private const ADDED_COLUMNS = [
        'published_source' => ['type' => ColumnType::Json, 'nullable' => true, 'default' => null],
        'draft_source' => ['type' => ColumnType::Json, 'nullable' => true, 'default' => null],
        'draft_revision' => ['type' => ColumnType::UnsignedInteger, 'nullable' => false, 'default' => 0],
        'draft_base_version' => ['type' => ColumnType::UnsignedInteger, 'nullable' => true, 'default' => null],
        'draft_origin' => ['type' => ColumnType::Text, 'nullable' => true, 'default' => null],
        'draft_updated_by' => ['type' => ColumnType::UnsignedInteger, 'nullable' => true, 'default' => null],
        'draft_updated_at' => ['type' => ColumnType::DateTime, 'nullable' => true, 'default' => null],
        'published_by' => ['type' => ColumnType::UnsignedInteger, 'nullable' => true, 'default' => null],
        'published_at' => ['type' => ColumnType::DateTime, 'nullable' => true, 'default' => null],
        'blueprint_version' => ['type' => ColumnType::UnsignedInteger, 'nullable' => false, 'default' => 1],
    ];

    public function testNeutralSchemaKeepsLiveIdentityAndAddsTheDraftContract(): void
    {
        $table = SchemaDefinition::load()->table('notification_templates');

        foreach (['subject', 'body_text', 'body_html', 'body_short', 'body_version', 'state'] as $liveColumn) {
            self::assertTrue($table->hasColumn($liveColumn), $liveColumn);
        }

        foreach (self::ADDED_COLUMNS as $name => $expected) {
            $column = $table->column($name);
            self::assertSame($expected['type'], $column->type, $name . ' type');
            self::assertSame($expected['nullable'], $column->nullable, $name . ' nullable');
            self::assertSame($expected['default'], $column->default, $name . ' default');
        }

        self::assertSame(1, $table->column('body_version')->default);
        self::assertSame('published', $table->column('state')->default);
        self::assertTrue(array_reduce(
            $table->indexes,
            static fn (bool $found, $index): bool => $found || (
                $index->name === 'notification_templates_uq'
                && $index->columns === ['template_key', 'locale', 'channel_key']
                && $index->unique
            ),
            false,
        ));
    }

    /**
     * The Joomla install creates the draft contract on the live table, and
     * writes no row while doing it.
     *
     * This was asserted against the 1.10.0 migration until R13 collapsed the
     * history: the product had never been released, so that migration described
     * an upgrade nobody ran. The claim moved to the install and did not change,
     * because the install is what a site gets now. A published template's rows
     * are not rewritten by an install for the plainest of reasons, which the
     * assertion still states: the script contains no UPDATE at all.
     */
    public function testTheJoomlaInstallCreatesTheDraftContractWithoutRewritingPublishedRows(): void
    {
        $block = $this->createBlock($this->joomlaInstall(), 'notification_templates');

        foreach (self::ADDED_COLUMNS as $column => $expected) {
            self::assertSame(1, substr_count($block, '`' . $column . '` '), $column);
            self::assertSame(
                $expected['nullable'],
                !str_contains($this->columnLine($block, $column), ' NOT NULL'),
                $column . ' nullability differs between the schema and the install',
            );
        }

        self::assertDoesNotMatchRegularExpression('/\b(?:UPDATE|DELETE|DROP)\b/i', $this->joomlaInstall());
    }

    /**
     * The WordPress schema declares the same table, and the version the
     * activator records is the one the repository declares and can reach.
     *
     * dbDelta is handed the compiled schema and nothing else, so a statement
     * here that was not a CREATE would either be ignored, which makes it a lie,
     * or run, which makes an activation a write against somebody's data. The
     * version half is what stops a site being told it upgraded to a number
     * nothing installed: the activator's constant, the repository's pin and the
     * install floor are three numbers that have to line up, and after the
     * collapse the floor is what makes the third one reachable at all.
     */
    public function testTheWordPressSchemaIsAdditiveDbDeltaOnlyAndVersionsStayAligned(): void
    {
        $statements = $this->wordpressSchema();
        $block = $this->createBlock(implode(";\n", $statements) . ';', 'notification_templates');

        self::assertStringStartsWith('CREATE TABLE `{prefix}vidyalms_notification_templates`', $block);
        self::assertDoesNotMatchRegularExpression('/\b(?:UPDATE|DELETE|DROP|INSERT|ALTER)\b/i', $block);

        foreach (array_keys(self::ADDED_COLUMNS) as $column) {
            self::assertSame(1, substr_count($block, '`' . $column . '` '), $column);
            self::assertSame(
                $this->columnLine($this->createBlock($this->joomlaInstall(), 'notification_templates'), $column),
                $this->columnLine($block, $column),
                $column . ' is declared differently on the two hosts',
            );
        }

        $currentVersion = require self::SCHEMA_VERSION;
        $floor = require self::SCHEMA_FLOOR;
        self::assertIsString($currentVersion);
        self::assertIsString($floor);
        self::assertTrue(version_compare($currentVersion, $floor, '>='));

        $activator = file_get_contents(self::WORDPRESS_ACTIVATOR);
        self::assertIsString($activator);
        self::assertStringContainsString("SCHEMA_VERSION = '" . $currentVersion . "'", $activator);
    }

    private function joomlaInstall(): string
    {
        $sql = file_get_contents(self::JOOMLA_INSTALL);
        self::assertIsString($sql);

        return (string) preg_replace('/^\s*--.*$/m', '', str_replace("\r\n", "\n", $sql));
    }

    /** @return list<string> */
    private function wordpressSchema(): array
    {
        $schema = require self::WORDPRESS_SCHEMA;
        self::assertIsArray($schema);
        $statements = [];

        foreach ($schema as $statement) {
            self::assertIsString($statement);
            $statements[] = $statement;
        }

        return $statements;
    }

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

    private function columnLine(string $block, string $column): string
    {
        $found = [];
        self::assertSame(
            1,
            preg_match('/^\s*(`' . $column . '` [^\n]*?),?$/m', $block, $found),
            $column . ' has no declaration in the block',
        );

        return trim($found[1]);
    }
}
