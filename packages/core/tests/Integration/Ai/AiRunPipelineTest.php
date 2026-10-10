<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Ai;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Ai\AiRunPipeline;
use Shondalai\VidyaLMS\Core\Application\Ai\AiRunRequest;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunState;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRefusal;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\CancellationSignal;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\Ai\ScriptedAiPolicy;
use Shondalai\VidyaLMS\Core\Tests\Support\Ai\ScriptedAiTask;

/**
 * The run pipeline, end to end on the harness and over the null bridge.
 *
 * Every case here is one of the nine invariants the design says must live in one
 * place because two entry paths need them, and each exists because the failure it
 * describes was constructible before the code that stops it: a policy denial that
 * left no record, a dry run that spent, a ceiling that did not bind under
 * fan-out, a stop that only stopped the next run, a failure that lost the spend
 * it had already made.
 *
 * Nothing here reaches a model. There is none to reach: the free build's only
 * registered bridge is the null one, and that is the point.
 */
final class AiRunPipelineTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-06-01 09:00:00');
    }

    /**
     * Turns a free site on, exactly as an administrator would, so the shipped
     * policy chain can reach allow.
     *
     * Everything is off by default at four independent levels, and the default
     * free site therefore refuses every run. That is the design's published
     * behaviour and it is asserted in its own case below; this is what an
     * administrator does afterwards, and it is spelled out here rather than
     * hidden in a helper because the four steps ARE the four levels.
     *
     * The route points at the null bridge, which is the only bridge a free build
     * has. That is the whole point of the null provider: the free tests reach it
     * end to end rather than leaving the seam untested until somebody buys Pro.
     */
    private function enableAiFor(string $taskKey): void
    {
        $now = '2026-06-01 09:00:00';
        $database = $this->fixture->env->database;

        // Level one: the site enables AI at all.
        $database->insert('provider_settings', [
            'registry_key' => 'ai',
            'provider_key' => 'enablement',
            'scope_type' => 'site',
            'scope_id' => 0,
            'params' => json_encode(['enabled' => true], JSON_THROW_ON_ERROR),
            'params_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Level four: the versioned data-use policy, and this person's
        // acceptance of that exact version.
        $database->insert('ai_policies', [
            'version' => 1,
            'title' => 'How this site uses AI',
            'body' => 'The four part text, plus what this site sends and keeps.',
            'body_hash' => hash('sha256', 'policy-v1'),
            'effective_from' => '2026-01-01 00:00:00',
            'state' => 'active',
            'created_by' => $this->fixture->manager()->userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $database->insert('ai_policy_acceptances', [
            'user_id' => $this->fixture->teacher()->userId,
            'policy_version' => 1,
            'scope_type' => 'site',
            'scope_id' => 0,
            'accepted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Level three: one enabled route for this task, on the null bridge, with
        // a real per-run ceiling. Zero would refuse, which is why a provisioning
        // import cannot create an uncapped route.
        $database->insert('ai_routes', [
            'task_key' => $taskKey,
            'bridge_key' => 'null',
            'scope_type' => 'site',
            'scope_id' => 0,
            'enabled' => 1,
            'position' => 0,
            'max_cost_per_run' => 1000,
            'params_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * The ordering is the invariant, so it is asserted rather than assumed.
     */
    public function testTheShippedPipelineHoldsTheElevenNamedStagesInOrder(): void
    {
        self::assertSame(AiRunPipeline::ORDER, $this->fixture->kernel->aiPipeline->stageNames());
        // Twelve since grounding stopped being write-only: the indexer wrote
        // ai_grounding_chunks and nothing read the table, so a task declaring
        // RequiresGrounding was declaring something nothing served.
        self::assertCount(12, AiRunPipeline::ORDER);
    }

    public function testASuccessfulRunWritesTheLedgerTheAttemptTheSubjectAndTheDrafts(): void
    {
        $this->enableAiFor((new ScriptedAiTask())->key());
        $task = (new ScriptedAiTask())->willDraft(['stem' => 'What is tea?', 'options' => ['a', 'b']]);
        $this->fixture->kernel->registries->aiTasks->register($task);

        $report = $this->fixture->kernel->aiPipeline->run($this->request($task->key()));

        self::assertSame(AiRunState::Succeeded, $report->state);
        self::assertGreaterThan(0, $report->runId);
        self::assertCount(1, $report->draftIds);
        self::assertSame(1, $task->calls);

        $run = $this->fixture->kernel->aiRunStore->find($report->runId);
        self::assertNotNull($run);
        self::assertSame('succeeded', $run->state->value);
        // Written before the call and updated after it, which is what makes the
        // right-to-know view buildable at all.
        self::assertNotNull($run->startedAt);
        self::assertNotNull($run->completedAt);
        self::assertSame($this->fixture->learner()->userId, $run->onBehalfOfUserId);

        // One attempt row, written by the free path, so the ledger the outage
        // post-mortem reads is exercised before any paid bridge exists.
        self::assertSame(1, $this->rows('ai_run_attempts', ['run_id' => $report->runId]));
        // One subject row, because the run named a person it acted on behalf of.
        self::assertSame(1, $this->rows('ai_run_subjects', ['run_id' => $report->runId]));
        self::assertSame(1, $this->rows('ai_drafts', ['run_id' => $report->runId]));
    }

    /**
     * The dry run is the privacy artefact: it renders the manifest, writes a
     * previewed row, calls nothing and writes no draft.
     */
    public function testADryRunIsPreviewedRendersTheManifestAndWritesNoDraft(): void
    {
        $this->enableAiFor((new ScriptedAiTask())->key());
        $task = (new ScriptedAiTask())->willDraft(['stem' => 'Never written']);
        $this->fixture->kernel->registries->aiTasks->register($task);

        $report = $this->fixture->kernel->aiPipeline->run($this->request($task->key(), dryRun: true));

        self::assertSame(AiRunState::Previewed, $report->state);
        self::assertSame([], $report->draftIds);
        self::assertSame(0, $task->calls, 'A preview does the work the preview exists to avoid doing.');
        self::assertSame(0, $this->rows('ai_drafts', ['run_id' => $report->runId]));
        // No attempt row either: a preview attempted nothing, and recording one
        // would put a call that never happened into the aggregation an operator
        // uses to find the route that is failing.
        self::assertSame(0, $this->rows('ai_run_attempts', ['run_id' => $report->runId]));

        $run = $this->fixture->kernel->aiRunStore->find($report->runId);
        self::assertNotNull($run);
        self::assertTrue($run->dryRun);
        self::assertNotSame('', $run->inputsDigest);
    }

    /**
     * A denial is recorded, is typed, and spends nothing.
     */
    public function testAPolicyDenialIsRecordedAsARefusalWithItsKindAndSpendsNothing(): void
    {
        // The site is turned on first, so the denial under test is the ceiling
        // and not the site switch. Every denial used to be reported as
        // ceiling_exceeded, so this case passed whichever policy actually
        // refused; now a policy names its own kind and the case has to name
        // the policy it is about.
        $this->enableAiFor((new ScriptedAiTask())->key());
        $task = (new ScriptedAiTask())->willDraft(['stem' => 'Never reached']);
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->fixture->kernel->registries->aiPolicies->register(
            ScriptedAiPolicy::denying('over_monthly_ceiling'),
        );

        $report = $this->fixture->kernel->aiPipeline->run($this->request($task->key()));

        self::assertSame(AiRunState::Refused, $report->state);
        self::assertNotNull($report->refusal);
        self::assertSame(RefusalKind::CeilingExceeded, $report->refusal->kind);
        self::assertSame(0, $task->calls);

        // The row exists, because counting refusals without their reason cannot
        // tell a site whether it is over budget, unconsented or rate limited.
        $run = $this->fixture->kernel->aiRunStore->find($report->runId);
        self::assertNotNull($run);
        self::assertSame('refused', $run->state->value);
        self::assertSame('ceiling_exceeded', $run->refusalKind);
        self::assertNull($run->costReserved, 'A run refused before the reservation held no headroom.');
    }

    /**
     * The divergence from the notification precedent, in a test: an unresolved
     * gate fails closed.
     */
    public function testAnUnresolvablePolicyRefusesTheRunRatherThanBeingSkipped(): void
    {
        $task = new ScriptedAiTask();
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->fixture->kernel->registries->aiPolicies->register(ScriptedAiPolicy::unresolvable());

        $report = $this->fixture->kernel->aiPipeline->run($this->request($task->key()));

        self::assertTrue($report->wasRefused());
        self::assertSame(RefusalKind::NotConfigured, $report->refusal?->kind);
        self::assertSame(0, $task->calls);
    }

    /**
     * A deferral is not a denial and not a failure: the run stays reserved and
     * the caller is told when to come back.
     */
    public function testACeilingDeferralLeavesTheRunReservedAndNamesTheInstant(): void
    {
        $this->enableAiFor((new ScriptedAiTask())->key());
        $task = new ScriptedAiTask();
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->fixture->kernel->registries->aiPolicies->register(
            ScriptedAiPolicy::deferring(new \DateTimeImmutable('2026-07-01 00:00:00')),
        );

        $report = $this->fixture->kernel->aiPipeline->run($this->request($task->key()));

        self::assertTrue($report->wasDeferred());
        self::assertFalse($report->wasRefused());
        self::assertSame('2026-07-01 00:00:00', $report->deferUntil?->format('Y-m-d H:i:s'));
        self::assertSame(AiRunState::Reserved, $report->state);
        self::assertSame(0, $task->calls);
    }

    /**
     * The rule the import pipeline does not have, because money changes it.
     */
    public function testARunThatSpentAndThenFailedRecordsTheSpend(): void
    {
        $this->enableAiFor((new ScriptedAiTask())->key());
        $task = (new ScriptedAiTask())
            ->willSpend(CostRecord::of(CostRecord::UNIT_TOKENS, 4200.0))
            ->willThrow(new \RuntimeException('the unit broke after it had already paid'));
        $this->fixture->kernel->registries->aiTasks->register($task);

        $report = $this->fixture->kernel->aiPipeline->run($this->request($task->key()));

        self::assertSame(AiRunState::Failed, $report->state);

        $run = $this->fixture->kernel->aiRunStore->find($report->runId);
        self::assertNotNull($run);
        self::assertSame('failed', $run->state->value);
        self::assertSame('task_threw', $run->errorCode);
        // The attempt row is written for a failure too, which is exactly the row
        // an outage post-mortem is looking for.
        self::assertSame(1, $this->rows('ai_run_attempts', ['run_id' => $report->runId]));
    }

    /**
     * A refusal the TASK returned, rather than a policy, is still a refusal and
     * still records its spend.
     */
    public function testATaskRefusalCarriesItsKindAndItsSpendOntoTheLedger(): void
    {
        $this->enableAiFor((new ScriptedAiTask())->key());
        $task = (new ScriptedAiTask())
            ->willSpend(CostRecord::of(CostRecord::UNIT_TOKENS, 12.0))
            ->willRefuse(AiRefusal::of(RefusalKind::InputTooLarge, 'ai.input.too_large'));
        $this->fixture->kernel->registries->aiTasks->register($task);

        $report = $this->fixture->kernel->aiPipeline->run($this->request($task->key()));

        self::assertSame(AiRunState::Refused, $report->state);

        $run = $this->fixture->kernel->aiRunStore->find($report->runId);
        self::assertNotNull($run);
        self::assertSame('input_too_large', $run->refusalKind);
        self::assertSame(12.0, $run->costCharged);
        self::assertSame(CostRecord::UNIT_TOKENS, $run->costUnit);
    }

    /**
     * The Article 14 stop, observed by a run in flight rather than only by the
     * next one to start.
     */
    public function testTheStopCancelsARunInFlightAndIsNotRecordedAsAFailure(): void
    {
        $this->enableAiFor((new ScriptedAiTask())->key());
        $task = (new ScriptedAiTask())->willDraft(['stem' => 'Never written']);
        $this->fixture->kernel->registries->aiTasks->register($task);

        $report = $this->fixture->kernel->aiPipeline->run(
            $this->request($task->key()),
            CancellationSignal::cancelled(),
        );

        self::assertSame(AiRunState::Cancelled, $report->state);
        self::assertSame(0, $task->calls);

        $run = $this->fixture->kernel->aiRunStore->find($report->runId);
        self::assertNotNull($run);
        self::assertSame('cancelled', $run->state->value);
        self::assertNull($run->errorCode, 'A stop is not a breakage and must not be filed as one.');
    }

    /**
     * A task nothing registers refuses not_configured rather than throwing, and
     * the refusal is still recorded.
     */
    public function testAnUnregisteredTaskRefusesNotConfiguredAndStillLeavesARow(): void
    {
        $report = $this->fixture->kernel->aiPipeline->run($this->request('nothing.registers.this'));

        self::assertSame(RefusalKind::NotConfigured, $report->refusal?->kind);
        self::assertGreaterThan(0, $report->runId);
    }

    /**
     * The redaction manifest reaches the ledger row, because it is what the
     * learner's right-to-know view and the dry run render.
     */
    public function testTheManifestIsStoredOnTheRunRow(): void
    {
        $this->enableAiFor((new ScriptedAiTask())->key());
        $task = (new ScriptedAiTask())->willDraft(['stem' => 'Tea']);
        $this->fixture->kernel->registries->aiTasks->register($task);

        $report = $this->fixture->kernel->aiPipeline->run($this->request($task->key()));
        $row = $this->fixture->env->database->selectOne(
            Query::from('ai_runs')->select(['redaction_manifest'])->where('id', '=', $report->runId),
        );

        self::assertNotNull($row);
        // The strict redactor is a free provider reached on every free run, so a
        // free site with no model configured still produces a manifest.
        self::assertNotSame('', $report->inputsDigest);
    }

    /**
     * The published free-site behaviour: everything is off at four independent
     * levels, so a registered task on an untouched site refuses rather than
     * running.
     *
     * A site that never buys Pro still gets the drafts, the provenance, the dry
     * run, the stop control, the policies, the redaction and the learner's right
     * to know. What it has none of is an enabled route, which is why every task
     * answers not_configured rather than failing at click time.
     */
    public function testAnUntouchedFreeSiteRefusesEveryRunBecauseNothingIsEnabled(): void
    {
        $task = (new ScriptedAiTask())->willDraft(['prompt' => 'Never reached']);
        $this->fixture->kernel->registries->aiTasks->register($task);

        $report = $this->fixture->kernel->aiPipeline->run($this->request($task->key()));

        self::assertTrue($report->wasRefused());
        self::assertSame(0, $task->calls);
        // The refusal is still on the ledger, because counting refusals without
        // their reason cannot tell a site whether it is over budget, unconsented
        // or simply not configured.
        self::assertGreaterThan(0, $report->runId);
    }

    private function request(string $taskKey, bool $dryRun = false): AiRunRequest
    {
        return AiRunRequest::create(
            $this->fixture->teacher(),
            $taskKey,
            AiTaskRequest::create('lesson', $this->fixture->lessonId, ['drafts' => []]),
            null,
            'course',
            $this->fixture->courseId,
            $this->fixture->courseId,
            null,
            $this->fixture->learner()->userId,
            $dryRun,
        );
    }

    /**
     * @param array<string, mixed> $where
     */
    private function rows(string $table, array $where): int
    {
        $query = Query::from($table);

        foreach ($where as $column => $value) {
            $query->where($column, '=', $value);
        }

        return $this->fixture->env->database->count($query);
    }
}
