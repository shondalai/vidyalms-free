<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Ai;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Schema\ColumnType;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;

/**
 * The AI spine, as the neutral schema declares it.
 *
 * Twelve of the thirteen tables the design's AI spine lists land here.
 * ai_chunk_vectors is deliberately absent: it is written by Pro only, its
 * reader is the embedding-backed retriever, and stage C owns both. Declaring
 * an empty table now would ship a column set nothing free can fill and nothing
 * free can read, which is the provider-nothing-calls failure one step further
 * in.
 *
 * The assertions here are the ones a migration review cannot make by eye: that
 * every StoredShape-versioned JSON column carries its mirrored version column,
 * that the three refused columns are absent by construction, that the columns
 * whose nullability IS the design decision are nullable or not as stated, and
 * that the purge predicates are indexed.
 */
final class AiSpineSchemaTest extends TestCase
{
    /**
     * The twelve free tables, in the order the design lists them.
     *
     * @var list<string>
     */
    private const SPINE = [
        'ai_routes',
        'ai_policies',
        'ai_policy_acceptances',
        'ai_runs',
        'ai_run_attempts',
        'ai_run_subjects',
        'ai_drafts',
        'ai_draft_findings',
        'ai_artefact_provenance',
        'ai_grounding_chunks',
        'ai_jobs',
        'ai_job_map',
    ];

    /**
     * Every provider-shaped JSON column in the AI spine, with the real column
     * its StoredShape version is mirrored into.
     *
     * This is the list the versioned-stored-shapes section publishes, narrowed
     * to the AI half. A JSON column with no mirrored version is a batch upgrade
     * that has to be a JSON path query, and a version column with no JSON
     * column beside it is a column nobody writes.
     *
     * @var array<string, string>
     */
    private const VERSIONED_JSON = [
        'ai_routes.params' => 'params_version',
        'ai_policies.enabled_tasks' => 'enabled_tasks_version',
        'ai_policy_acceptances.evidence' => 'evidence_version',
        'ai_runs.redaction_manifest' => 'redaction_manifest_version',
        'ai_drafts.payload' => 'payload_version',
        'ai_drafts.edited_payload' => 'edited_payload_version',
        'ai_drafts.citations' => 'citations_version',
        'ai_draft_findings.evidence' => 'evidence_version',
        'ai_jobs.options' => 'options_version',
        'ai_jobs.totals' => 'totals_version',
    ];

    private static function schema(): SchemaDefinition
    {
        return SchemaDefinition::load();
    }

    public function testTheTwelveFreeSpineTablesAreDeclared(): void
    {
        $schema = self::schema();

        foreach (self::SPINE as $name) {
            self::assertArrayHasKey(
                $name,
                $schema->tables,
                $name . ' is part of the AI spine and every one of its tables is free.',
            );
        }
    }

    /**
     * Stage C owns the vector store, so stage B does not declare it.
     */
    public function testTheVectorStoreIsNotDeclaredYet(): void
    {
        self::assertArrayNotHasKey(
            'ai_chunk_vectors',
            self::schema()->tables,
            'ai_chunk_vectors is written by Pro only and belongs to the model-access stage.',
        );
    }

    public function testEveryVersionedJsonColumnCarriesItsMirroredVersionColumn(): void
    {
        $schema = self::schema();

        foreach (self::VERSIONED_JSON as $path => $versionColumn) {
            [$tableName, $columnName] = explode('.', $path, 2);
            $table = $schema->table($tableName);

            self::assertTrue($table->hasColumn($columnName), $path . ' must exist.');
            self::assertSame(
                ColumnType::Json,
                $table->column($columnName)->type,
                $path . ' is provider-shaped and therefore JSON.',
            );
            self::assertTrue(
                $table->hasColumn($versionColumn),
                $path . ' needs its StoredShape version mirrored into ' . $tableName . '.' . $versionColumn . '.',
            );
            self::assertSame(
                ColumnType::UnsignedInteger,
                $table->column($versionColumn)->type,
                $tableName . '.' . $versionColumn . ' is an indexable integer, not part of the document.',
            );
        }
    }

    /**
     * The absence is the statement. A future contributor adding one of these
     * has to add a column and argue for it in a migration review, rather than
     * setting a value on a field that was quietly waiting for them.
     */
    public function testNoAiTableCarriesAScoreAConfidenceOrARiskColumn(): void
    {
        $refused = ['ai_score', 'ai_confidence', 'ai_risk', 'score', 'confidence', 'risk'];

        foreach (self::SPINE as $name) {
            foreach (array_keys(self::schema()->table($name)->columns) as $column) {
                self::assertNotContains(
                    $column,
                    $refused,
                    $name . '.' . $column . ' is a scored field on an AI table, which this design refuses.',
                );
            }
        }
    }

    /**
     * Not checked and checked-and-failed are different facts, so the column is
     * three valued.
     */
    public function testSchemaValidIsNullableAndTokensAreNullableRatherThanZero(): void
    {
        $runs = self::schema()->table('ai_runs');

        foreach (['schema_valid', 'tokens_in', 'tokens_out', 'cost_reserved', 'cost_charged'] as $column) {
            self::assertTrue(
                $runs->column($column)->nullable,
                'ai_runs.' . $column . ' is nullable, because unknown is honest and zero is a lie.',
            );
        }
    }

    /**
     * Set at insert and refreshed on every state change, so the drafts nobody
     * ever opened match a purge predicate rather than surviving on the run's
     * clock.
     */
    public function testDraftRetainUntilIsNotNullableAndIsIndexed(): void
    {
        $drafts = self::schema()->table('ai_drafts');

        self::assertFalse($drafts->column('retain_until')->nullable);
        self::assertSame(ColumnType::DateTime, $drafts->column('retain_until')->type);
        self::assertTrue(
            self::hasIndexOver($drafts->indexes, ['retain_until']),
            'the stale-draft and retention sweeps scan this column.',
        );

        $runs = self::schema()->table('ai_runs');
        self::assertFalse($runs->column('retain_until')->nullable);
        self::assertTrue(self::hasIndexOver($runs->indexes, ['retain_until']));
    }

    /**
     * The record that outlives the ledger: polymorphic in its owner, unique per
     * (subject, run), and carrying the denormalised task, model and ratio that
     * make its survival mean something.
     */
    public function testProvenanceIsPolymorphicUniquePerSubjectAndRunAndDenormalises(): void
    {
        $table = self::schema()->table('ai_artefact_provenance');

        foreach (
            ['task_key', 'task_version', 'prompt_ref', 'model', 'inputs_digest', 'disclosure_tier',
            'edit_distance_ratio', 'human_edited', 'accepted_by', 'accepted_at', 'superseded_at'] as $column
        ) {
            self::assertTrue($table->hasColumn($column), 'ai_artefact_provenance.' . $column);
        }

        // A log row is never updated, so it carries no updated_at.
        self::assertFalse($table->hasColumn('updated_at'));

        $unique = array_values(array_filter(
            $table->indexes,
            static fn (\Shondalai\VidyaLMS\Core\Schema\Index $index): bool => $index->unique,
        ));

        self::assertCount(1, $unique);
        self::assertSame(['subject_type', 'subject_id', 'run_id'], $unique[0]->columns);
    }

    /**
     * The right-to-know view reads this table, so it is one row per person per
     * run and never a single nullable column on the run.
     */
    public function testRunSubjectsIsUniquePerRunAndUserAndIndexedByUser(): void
    {
        $table = self::schema()->table('ai_run_subjects');

        self::assertTrue($table->hasColumn('role'));
        self::assertFalse($table->hasColumn('created_at'));
        self::assertTrue(self::hasIndexOver($table->indexes, ['run_id', 'user_id'], true));
        self::assertTrue(self::hasIndexOver($table->indexes, ['user_id', 'run_id']));
    }

    /**
     * The assertion that nothing an importer wrote is ever tagged
     * trusted_instruction needs a column to read.
     */
    public function testGroundingChunksCarryTrustAsARealIndexedColumn(): void
    {
        $table = self::schema()->table('ai_grounding_chunks');

        self::assertSame(ColumnType::Text, $table->column('trust')->type);
        self::assertSame('untrusted', $table->column('trust')->default);
        self::assertTrue(self::hasIndexOver($table->indexes, ['trust']));
        self::assertTrue(self::hasIndexOver($table->indexes, ['chunk_key'], true));
    }

    /**
     * A run that spent and then failed records the spend, which is why the job
     * carries a charged figure beside its reserved one and a resume instant
     * beside its status.
     */
    public function testJobsCarrySpendAndAResumeInstant(): void
    {
        $table = self::schema()->table('ai_jobs');

        self::assertTrue($table->column('cost_charged')->nullable);
        self::assertTrue($table->column('cost_reserved')->nullable);
        self::assertTrue($table->column('resume_at')->nullable);
        self::assertTrue($table->hasColumn('recipe_key'));
        self::assertTrue($table->hasColumn('step_index'));

        // No heartbeat column: the progress write moves updated_at, exactly as
        // import_jobs does.
        self::assertFalse($table->hasColumn('heartbeat_at'));
    }

    /**
     * A route with no declared per-run ceiling may not be selected, so the
     * default is the refusing value rather than an unlimited one.
     */
    public function testRoutesDefaultToDisabledAndToARefusingCeiling(): void
    {
        $table = self::schema()->table('ai_routes');

        self::assertSame(0, $table->column('enabled')->default);
        self::assertSame(0, $table->column('max_cost_per_run')->default);
        self::assertSame(0, $table->column('position')->default);
    }

    /**
     * @param list<\Shondalai\VidyaLMS\Core\Schema\Index> $indexes
     * @param list<string>                                $columns
     */
    private static function hasIndexOver(array $indexes, array $columns, bool $unique = false): bool
    {
        foreach ($indexes as $index) {
            if ($index->columns === $columns && $index->unique === $unique) {
                return true;
            }
        }

        return false;
    }
}
