<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Schema\ColumnType;
use Shondalai\VidyaLMS\Core\Schema\Index;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;
use Shondalai\VidyaLMS\Core\Schema\SchemaSource;
use Shondalai\VidyaLMS\Core\Schema\Table;

/**
 * schema/tables.php is the one declaration of the neutral schema, and the ways
 * a declaration can vanish without anybody noticing are covered here: a key
 * declared twice at any depth, which PHP resolves before any loader sees it; a
 * unique index too wide for the row format a customer's host may be using; an
 * index over a column MySQL cannot key without a prefix length; a file the
 * duplicate scan cannot read, which would otherwise report clean; and the file
 * ceasing to be plain data, which is asserted by requiring it in a subprocess
 * with no ini and no autoloader rather than by grepping for three spellings of
 * the violation.
 */
final class SchemaAuthorityTest extends TestCase
{
    private const CORE_SCHEMA = __DIR__ . '/../../../schema/tables.php';

    private const PRO_SCHEMA = __DIR__ . '/../../../../pro-core/schema/tables.php';

    /** @var list<string> */
    private array $temporary = [];

    /**
     * The public free source carries the core without pro-core beside it, so
     * the Pro half of these checks is skipped there. Keyed on the package
     * folder rather than the file: in the development monorepo a missing
     * schema file is a failure, not a skip.
     */
    private static function requireProCore(): void
    {
        if (!is_dir(\dirname(self::PRO_SCHEMA, 2))) {
            self::markTestSkipped('packages/pro-core is not part of this checkout.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        $this->temporary = [];
    }

    /**
     * The property, not three spellings of its violation.
     *
     * This asserted that the file contained none of "::tables()",
     * "::entitlementColumns()" and "Infrastructure\Persistence", which a
     * fragment reintroduced under any fourth spelling satisfies while a bare
     * require fatals again. So the file is required in a subprocess with no
     * ini and no autoloader, which is exactly what a packager, a compiler and
     * an installer do, and the returned array has to be the whole schema.
     */
    public function testTheNeutralSchemaIsPlainDataWithNoAutoloaderBooted(): void
    {
        $schema = realpath(self::CORE_SCHEMA);

        self::assertIsString($schema);

        $script = $this->schemaFile(
            "<?php\n\n\$tables = require " . var_export($schema, true) . ";\n"
            . "echo is_array(\$tables) ? count(\$tables) : 'not-an-array';\n"
        );

        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        $printed = implode("\n", $output);

        self::assertSame(0, $status, 'schema/tables.php cannot be required as data: ' . $printed);
        self::assertSame(
            (string) \count(SchemaDefinition::load(self::CORE_SCHEMA)->tableNames()),
            $printed,
            'A bare require must return the whole schema, with no loader booted first.'
        );
    }

    public function testTheProSchemaIsPlainDataToo(): void
    {
        self::requireProCore();
        $schema = realpath(self::PRO_SCHEMA);

        self::assertIsString($schema);

        $script = $this->schemaFile(
            "<?php\n\n\$tables = require " . var_export($schema, true) . ";\n"
            . "echo is_array(\$tables) ? count(\$tables) : 'not-an-array';\n"
        );

        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY) . ' -n ' . escapeshellarg($script) . ' 2>&1', $output, $status);

        self::assertSame(0, $status, implode("\n", $output));
        self::assertSame(
            (string) \count(SchemaDefinition::load(self::PRO_SCHEMA)->tableNames()),
            implode("\n", $output)
        );
    }

    public function testTheFourOrganisationFragmentClassesAreGone(): void
    {
        $directory = __DIR__ . '/../../../src/Infrastructure/Persistence/Organisation';

        foreach (
            ['OrganisationSchema', 'InvitationSchema', 'OrganisationLearningSchema', 'OrganisationUnitSchema'] as $class
        ) {
            self::assertFileDoesNotExist($directory . '/' . $class . '.php');
        }
    }

    public function testTheSixteenOrganisationTablesAreDeclaredInTheNeutralSchema(): void
    {
        $names = SchemaDefinition::load(self::CORE_SCHEMA)->tableNames();

        foreach (
            [
                'organisations', 'org_memberships', 'org_role_assignments', 'org_membership_events',
                'invitations', 'org_invitation_claims', 'org_invitation_limits',
                'org_access_termination_jobs', 'org_learning_locks', 'org_course_releases',
                'seat_pools', 'seat_assignments', 'seat_events', 'org_units', 'org_unit_closure',
                'org_notification_secrets',
            ] as $table
        ) {
            self::assertContains($table, $names);
        }
    }

    public function testTheEntitlementOrganisationColumnsAreDeclaredInTheNeutralSchema(): void
    {
        $entitlements = SchemaDefinition::load(self::CORE_SCHEMA)->table('entitlements');

        foreach (
            ['organisation_id', 'org_membership_id', 'source_ref_digest', 'revoked_reason', 'revoked_ref'] as $column
        ) {
            self::assertTrue($entitlements->hasColumn($column), $column);
        }
    }

    public function testNeitherShippedSchemaDeclaresATableTwice(): void
    {
        self::assertSame([], SchemaSource::duplicateTableKeys(self::CORE_SCHEMA));
        self::requireProCore();
        self::assertSame([], SchemaSource::duplicateTableKeys(self::PRO_SCHEMA));
    }

    public function testADuplicateTableKeyFailsTheLoad(): void
    {
        $path = $this->schemaFile(
            "<?php\n\nreturn [\n"
            . "    'widgets' => ['columns' => ['name' => 'string:60']],\n"
            . "    'gadgets' => ['columns' => ['name' => 'string:60']],\n"
            . "    'widgets' => ['columns' => ['label' => 'string:60']],\n"
            . "];\n"
        );

        self::assertSame(['widgets'], SchemaSource::duplicateTableKeys($path));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares "widgets" more than once');

        SchemaDefinition::load($path);
    }

    public function testAKeyNestedInsideATableIsNotReadAsATableKey(): void
    {
        $path = $this->schemaFile(
            "<?php\n\nreturn [\n"
            . "    'widgets' => ['columns' => ['name' => 'string:60', 'label' => 'string:60']],\n"
            . "    'gadgets' => ['columns' => ['name' => 'string:60', 'label' => 'string:60']],\n"
            . "];\n"
        );

        self::assertSame([], SchemaSource::duplicateTableKeys($path));
    }

    /**
     * A column replaced by a later duplicate is the same silent loss as a
     * table replaced by a later duplicate: the load succeeds, the shape is
     * well formed, and the default that survives is whichever was written
     * last. Before this the scan looked only at depth one and reported none.
     */
    public function testADuplicateColumnKeyInsideATableIsRefusedToo(): void
    {
        $path = $this->schemaFile(
            "<?php\n\nreturn [\n"
            . "    'vouchers' => ['columns' => [\n"
            . "        'state' => ['string:20', 'default' => 'draft'],\n"
            . "        'code' => 'string:60',\n"
            . "        'state' => ['string:20', 'default' => 'live'],\n"
            . "    ]],\n"
            . "];\n"
        );

        self::assertContains('vouchers.columns.state', SchemaSource::duplicateTableKeys($path));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('declares "vouchers.columns.state"');

        SchemaDefinition::load($path);
    }

    /**
     * Two entries of one index list both carry a "columns" key and that is not
     * a duplicate: they are two literals, and conflating them would refuse
     * every table in the shipped schema that declares more than one index.
     */
    public function testTwoIndexesInOneListAreNotReadAsADuplicate(): void
    {
        $path = $this->schemaFile(
            "<?php\n\nreturn [\n"
            . "    'vouchers' => ['columns' => ['code' => 'string:60', 'state' => 'string:20'],\n"
            . "        'indexes' => [['columns' => ['code']], ['columns' => ['state']]]],\n"
            . "];\n"
        );

        self::assertSame([], SchemaSource::duplicateTableKeys($path));
        self::assertCount(2, SchemaDefinition::load($path)->table('vouchers')->indexes);
    }

    /**
     * A shape the scan cannot read must fail loudly. Reporting no duplicates
     * for a file it never parsed is the one answer that makes the refusal
     * worse than useless.
     */
    public function testASchemaTheScanCannotReadIsRefusedRatherThanReportedClean(): void
    {
        $path = $this->schemaFile(
            "<?php\n\nreturn array(\n"
            . "    'widgets' => array('columns' => array('name' => 'string:60')),\n"
            . ");\n"
        );

        self::assertSame([], SchemaSource::duplicateTableKeys($path));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('a shape the duplicate-key scan does not understand');

        SchemaDefinition::load($path);
    }

    public function testATooWideUniqueIndexIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unique index "vouchers_scope_code_uq" declares 800 bytes');

        SchemaDefinition::fromArray([
            'vouchers' => [
                'columns' => ['scope' => 'string:10', 'code' => 'string:190'],
                'indexes' => [
                    ['columns' => ['scope', 'code'], 'unique' => true, 'name' => 'vouchers_scope_code_uq'],
                ],
            ],
        ]);
    }

    public function testAUniqueIndexAtTheLimitIsAccepted(): void
    {
        $schema = SchemaDefinition::fromArray([
            'vouchers' => [
                'columns' => ['digest' => 'string:64', 'organisation_id' => 'uint'],
                'indexes' => [
                    ['columns' => ['digest', 'organisation_id'], 'unique' => true, 'name' => 'vouchers_digest_uq'],
                ],
            ],
        ]);

        self::assertCount(1, $schema->table('vouchers')->indexes);
    }

    public function testAWideNonUniqueIndexIsLeftAlone(): void
    {
        $schema = SchemaDefinition::fromArray([
            'vouchers' => [
                'columns' => ['scope' => 'string:30', 'code' => 'string:190'],
                'indexes' => [['columns' => ['scope', 'code']]],
            ],
        ]);

        self::assertCount(1, $schema->table('vouchers')->indexes);
    }

    public function testAJsonColumnCannotCarryAUniqueIndex(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('needs a prefix length');

        SchemaDefinition::fromArray([
            'vouchers' => [
                'columns' => ['payload' => 'json'],
                'indexes' => [['columns' => ['payload'], 'unique' => true, 'name' => 'vouchers_payload_uq']],
            ],
        ]);
    }

    /**
     * MySQL rejects a plain KEY over a json or text column exactly as it
     * rejects a UNIQUE KEY, and the refusal used to be unreachable for one:
     * the width walk returned early for every non-unique index, so a schema
     * declaring KEY (payload) compiled cleanly and failed with error 3152 on
     * the customer's host.
     */
    public function testAJsonColumnCannotCarryAPlainIndexEither(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('needs a prefix length');

        SchemaDefinition::fromArray([
            'vouchers' => [
                'columns' => ['payload' => 'json'],
                'indexes' => [['columns' => ['payload'], 'name' => 'vouchers_payload_ix']],
            ],
        ]);
    }

    public function testALongTextColumnCannotCarryAPlainIndexEither(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('needs a prefix length');

        SchemaDefinition::fromArray([
            'vouchers' => [
                'columns' => ['body' => 'text'],
                'indexes' => [['columns' => ['body'], 'name' => 'vouchers_body_ix']],
            ],
        ]);
    }

    public function testEveryShippedUniqueIndexPassesTheWidthAssertion(): void
    {
        self::assertNotSame([], SchemaDefinition::load(self::CORE_SCHEMA)->tableNames());
        self::requireProCore();
        self::assertNotSame([], SchemaDefinition::load(self::PRO_SCHEMA)->tableNames());
    }

    /**
     * The 767 byte refusal is unique-only on purpose, because narrowing a
     * unique index changes what the database guarantees while narrowing a
     * plain one is a performance change. That leaves the plain indexes over
     * the floor unrefused, so they are counted here instead: exactly two are
     * shipped, both the same (source, source_ref) pair, and a third has to
     * pass through this test rather than through a customer's install log.
     */
    public function testExactlyTwoShippedPlainIndexesAreOverTheLegacyRowFormatFloor(): void
    {
        self::requireProCore();
        $wide = [];

        foreach ([self::CORE_SCHEMA, self::PRO_SCHEMA] as $file) {
            foreach (SchemaDefinition::load($file)->tables as $table) {
                foreach ($table->indexes as $index) {
                    if ($index->unique || self::declaredWidth($table, $index) <= 767) {
                        continue;
                    }

                    $wide[] = $table->name . '.' . $index->name;
                }
            }
        }

        sort($wide);

        self::assertSame(
            ['cohort_members.cohort_members_source_source_ref_ix', 'entitlements.entitlements_source_source_ref_ix'],
            $wide,
            'A plain index over 767 bytes was added. Narrow it, or record it in SchemaDefinition with its reason.'
        );
    }

    private static function declaredWidth(Table $table, Index $index): int
    {
        $width = 0;

        foreach ($index->columns as $columnName) {
            $column = $table->column($columnName);
            $width += match ($column->type) {
                ColumnType::Text => 4 * (int) $column->length,
                ColumnType::Identifier, ColumnType::Integer, ColumnType::UnsignedInteger => 4,
                ColumnType::BigInteger, ColumnType::Float => 8,
                ColumnType::Boolean => 1,
                ColumnType::DateTime => 5,
                ColumnType::Date => 3,
                ColumnType::Decimal => 4 * (int) ceil(((int) $column->precision) / 9),
                // Unreachable: SchemaDefinition refuses these on any index.
                ColumnType::Json, ColumnType::LongText, ColumnType::MediumText => PHP_INT_MAX,
            };
        }

        return $width;
    }

    private function schemaFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/vidyalms-schema-' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($path, $contents);
        $this->temporary[] = $path;

        return $path;
    }
}
