<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Schema\Index;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;

/**
 * The assessment foundation and the neutral schema must describe the same
 * database, and the two hosts must install the same one.
 *
 * This file used to compare the 1.1.0 migration against the schema, because a
 * migration is hand written and a typo in one is discovered on a customer's
 * site. R13 removed the migration: the product had never been released, so
 * 1.1.0 described an upgrade nobody had run, and the history collapsed into
 * one compiled install. The comparison survives with a different left-hand
 * side, the install script, which is what a site is built from now.
 *
 * The two pins the migration existed for did not survive as SQL and were never
 * really SQL's business. "New items default to guess_neutral, existing items
 * are pinned to plus_minus" is a rule about how a definition naming no policy
 * resolves, and it lives on the question type providers, in
 * ScoringPolicyAwareInterface::legacyPolicy(). A migration writing it into rows
 * was one site's copy of that rule; the providers are the rule itself, and they
 * answer for a site that installed yesterday as well as for one that did not.
 */
final class SchemaMigrationTest extends TestCase
{
    private const JOOMLA_INSTALL =
        __DIR__ . '/../../../../../packages/adapter-joomla/com_vidyalms/admin/sql/install.mysql.utf8.sql';

    private const WORDPRESS_SCHEMA =
        __DIR__ . '/../../../../../packages/adapter-wordpress/vidyalms/schema/schema.php';

    /** The tables the assessment foundation adds, as the design's schema section lists them. */
    private const ADDED_TABLES = [
        'attempt_questions',
        'question_definitions',
        'attempt_answer_events',
        'stimuli',
    ];

    private const ADDED_COLUMNS = [
        'questions' => [
            'definition_version',
            'response_shape',
            'scored',
            'scoring_policy',
            'scoring_params',
            'external_scored',
            'stimulus_id',
        ],
        'attempts' => ['presentation_seed', 'policy_snapshot', 'behaviour', 'expires_at'],
        'attempt_answers' => ['ui_state', 'raw_input', 'presentation_form', 'certainty'],
        'attempt_grades' => [
            'part_id',
            'raw_score',
            'min_score',
            'policy_key',
            'scoring_params',
            'session_status',
            'score_status',
            'state',
            'answered_status',
            'feedback_ids',
            'version',
        ],
        'quizzes' => ['shuffle_questions', 'shuffle_options', 'one_per_page'],
    ];

    private function schema(): SchemaDefinition
    {
        return SchemaDefinition::load();
    }

    private function joomlaInstall(): string
    {
        $sql = file_get_contents(self::JOOMLA_INSTALL);
        self::assertIsString($sql, 'the Joomla install script is missing');

        return (string) preg_replace('/^\s*--.*$/m', '', str_replace("\r\n", "\n", $sql));
    }

    private function wordpressSchema(): string
    {
        /** @var mixed $statements */
        $statements = require self::WORDPRESS_SCHEMA;
        self::assertIsArray($statements);
        $out = '';

        foreach ($statements as $statement) {
            self::assertIsString($statement);
            $out .= $statement . ";\n";
        }

        return $out;
    }

    public function testEveryAddedTableIsDeclaredInTheNeutralSchema(): void
    {
        $names = $this->schema()->tableNames();

        foreach (self::ADDED_TABLES as $table) {
            self::assertContains($table, $names);
        }
    }

    public function testBothHostsInstallEveryAddedTable(): void
    {
        $joomla = $this->joomlaInstall();
        $wordpress = $this->wordpressSchema();

        foreach (self::ADDED_TABLES as $table) {
            self::assertStringContainsString(
                'CREATE TABLE IF NOT EXISTS `#__vidyalms_' . $table . '`',
                $joomla,
            );
            self::assertStringContainsString(
                'CREATE TABLE `{prefix}vidyalms_' . $table . '`',
                $wordpress,
            );
        }
    }

    public function testEveryAddedColumnIsDeclaredInTheNeutralSchema(): void
    {
        $schema = $this->schema();

        foreach (self::ADDED_COLUMNS as $name => $columns) {
            $table = $schema->table($name);

            foreach ($columns as $column) {
                self::assertTrue($table->hasColumn($column), $name . '.' . $column);
            }
        }
    }

    public function testQuestionVersionUsageLookupIsIndexedAndInstalledOnBothHosts(): void
    {
        $indexNames = array_map(
            static fn (Index $index): string => $index->name,
            $this->schema()->table('attempt_questions')->indexes,
        );
        self::assertContains('attempt_questions_question_version_id_ix', $indexNames);

        foreach ([$this->joomlaInstall(), $this->wordpressSchema()] as $generated) {
            self::assertStringContainsString(
                'KEY `attempt_questions_question_version_id_ix` (`question_version_id`)',
                $generated,
            );
        }
    }

    public function testBothHostsInstallEveryAddedColumn(): void
    {
        $joomla = $this->joomlaInstall();
        $wordpress = $this->wordpressSchema();

        foreach (self::ADDED_COLUMNS as $table => $columns) {
            self::assertStringContainsString('`#__vidyalms_' . $table . '`', $joomla);
            self::assertStringContainsString('`{prefix}vidyalms_' . $table . '`', $wordpress);

            $joomlaBlock = $this->createBlock($joomla, $table);
            $wordpressBlock = $this->createBlock($wordpress, $table);

            foreach ($columns as $column) {
                self::assertSame(1, substr_count($joomlaBlock, '`' . $column . '` '), $table . '.' . $column);
                self::assertSame(
                    $this->columnLine($joomlaBlock, $column),
                    $this->columnLine($wordpressBlock, $column),
                    $table . '.' . $column . ' is declared differently on the two hosts',
                );
            }
        }
    }

    /**
     * The two pins the migration existed for are declared by the providers,
     * and no install writes them.
     *
     * Without them every existing multiple-choice and ordering question would
     * silently move to the corrected default and historic percentages would
     * change. The migration wrote them onto rows; the providers state them, so
     * a definition naming no policy resolves to the legacy pin whether the row
     * predates the field or a package wrote it yesterday. `chance_corrected`
     * is the parameter that makes the ordering pin mean anything: the legacy
     * behaviour is absolute_position with the correction off.
     */
    public function testTheTwoLegacyPinsAreDeclaredByTheProvidersAndWrittenByNoInstall(): void
    {
        $multipleChoice = new \Shondalai\VidyaLMS\Core\Provider\QuestionType\MultipleChoiceQuestionType(
            new \Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator(),
        );
        $ordering = new \Shondalai\VidyaLMS\Core\Provider\QuestionType\OrderingQuestionType(
            new \Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator(),
        );

        self::assertSame('plus_minus', $multipleChoice->legacyPolicy());
        self::assertSame('guess_neutral', $multipleChoice->defaultPolicy());
        self::assertSame('absolute_position', $ordering->legacyPolicy());
        self::assertSame(
            'chance_corrected',
            \Shondalai\VidyaLMS\Core\Provider\Scoring\OrderingMetric::PARAM_CHANCE_CORRECTED,
        );

        // The rule itself: a definition naming no policy grades under the pin,
        // and a type with no legacy behaviour falls through to its default.
        self::assertSame(
            'plus_minus',
            \Shondalai\VidyaLMS\Core\Registry\QuestionType\PolicyResolution::keyFor(
                [],
                'plus_minus',
                'guess_neutral',
            ),
        );
        self::assertSame(
            'guess_neutral',
            \Shondalai\VidyaLMS\Core\Registry\QuestionType\PolicyResolution::keyFor([], '', 'guess_neutral'),
        );

        foreach ([$this->joomlaInstall(), $this->wordpressSchema()] as $generated) {
            self::assertStringNotContainsString("'plus_minus'", $generated);
            self::assertStringNotContainsString("'absolute_position'", $generated);
            self::assertDoesNotMatchRegularExpression('/\bUPDATE\b/i', $generated);
        }
    }

    /**
     * Every policy key a shipped type can resolve to names a provider the free
     * build registers, or a free site would resolve a key the registry does
     * not contain.
     */
    public function testEveryPolicyKeyAShippedTypeCanResolveIsAFreeProvider(): void
    {
        $free = \Shondalai\VidyaLMS\Core\Provider\Scoring\FreeScoringPolicies::keys();
        $registries = new \Shondalai\VidyaLMS\Core\Registry\Registries();
        \Shondalai\VidyaLMS\Core\Boot\AssessmentProviders::registerInto(
            $registries,
            new \Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator(),
        );

        $resolved = 0;

        foreach ($registries->questionTypes->all() as $type) {
            if (!$type instanceof \Shondalai\VidyaLMS\Core\Registry\QuestionType\ScoringPolicyAwareInterface) {
                continue;
            }

            self::assertContains($type->defaultPolicy(), $free, $type->key() . ' default');
            ++$resolved;

            if ($type->legacyPolicy() !== null && $type->legacyPolicy() !== '') {
                self::assertContains($type->legacyPolicy(), $free, $type->key() . ' legacy pin');
            }
        }

        self::assertGreaterThan(0, $resolved, 'no shipped type declares a policy at all');
    }

    /**
     * The response shapes the providers declare are the ones the installed
     * column can store.
     *
     * The migration used to write each type's shape onto its rows, so the
     * generic fallback renderer keyed on a shape something produced. A fresh
     * install writes them through AddQuestion instead, and what is left to
     * check is that the column is wide enough for every declared shape and
     * that both hosts declare the same column.
     */
    public function testTheInstalledColumnStoresEveryShapeTheProvidersDeclare(): void
    {
        $registries = new \Shondalai\VidyaLMS\Core\Registry\Registries();
        \Shondalai\VidyaLMS\Core\Boot\AssessmentProviders::registerInto(
            $registries,
            new \Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator(),
        );

        $questions = $this->schema()->table('questions');
        $width = $questions->column('response_shape')->length;

        self::assertIsInt($width);
        self::assertTrue($questions->column('response_shape')->nullable);

        foreach ($registries->questionTypes->all() as $type) {
            self::assertInstanceOf(
                \Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionContractInterface::class,
                $type,
            );

            $shape = $type->responseShape()->columnValue();

            self::assertNotSame('', $shape, $type->key());
            self::assertLessThanOrEqual(
                $width,
                \strlen($shape),
                $type->key() . ' declares a shape wider than questions.response_shape can store',
            );
        }

        $joomlaBlock = $this->createBlock($this->joomlaInstall(), 'questions');
        $wordpressBlock = $this->createBlock($this->wordpressSchema(), 'questions');

        self::assertSame(
            '`response_shape` VARCHAR(' . $width . ') NULL',
            $this->columnLine($joomlaBlock, 'response_shape'),
        );
        self::assertSame(
            $this->columnLine($joomlaBlock, 'response_shape'),
            $this->columnLine($wordpressBlock, 'response_shape'),
        );
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
