<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Ai;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Ai\AiStaleDraftSweep;
use Shondalai\VidyaLMS\Core\Application\Ai\QueueAiJob;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftState;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiJobStatus;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\Ai\ScriptedAiPolicy;
use Shondalai\VidyaLMS\Core\Tests\Support\Ai\ScriptedAiTask;

/**
 * The job model, which is the import pipeline copied rather than reinvented,
 * plus the two rules money and regulation force on to it.
 *
 * The three inherited rules are here because the failures they stop are the same
 * failures: a build that rolled back the eleven lessons it made, a preview filed
 * as an applied run, and a late-finishing process writing over the ending an
 * operator chose. The two new ones are here because an import that fails costs
 * nothing and an AI build that fails has already paid.
 */
final class AiJobPipelineTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-06-01 09:00:00');
    }

    public function testQueueingWritesAPendingRowAndSchedulesOnePendingTaskKeyedOnTheJob(): void
    {
        $task = new ScriptedAiTask();
        $this->fixture->kernel->registries->aiTasks->register($task);

        $job = $this->fixture->kernel->queueAiJob->execute(
            $this->fixture->teacher(),
            $task->key(),
            null,
            'course',
            $this->fixture->courseId,
            ['depth' => 2],
            false,
            null,
            null,
            $this->fixture->courseId,
        );

        self::assertSame(AiJobStatus::Pending, $job->status);
        self::assertSame($task->key(), $job->taskKey);
        self::assertNull($job->recipeKey);
        self::assertSame(['depth' => 2], $job->options);
        self::assertSame(0, $task->calls, 'Queueing does the work later, or the request that asked for it times out.');

        $scheduled = $this->fixture->env->scheduler->tasks;
        self::assertNotSame([], $scheduled);
        $last = $scheduled[\count($scheduled) - 1];
        self::assertSame(QueueAiJob::TASK_TYPE, $last->type);
        self::assertSame(QueueAiJob::dedupeKeyFor($job->id), $last->dedupeKey);
    }

    /** A job names exactly one of a task and a recipe. */
    public function testQueueingRefusesAJobNamingNeitherATaskNorARecipe(): void
    {
        $this->expectException(ValidationException::class);

        $this->fixture->kernel->queueAiJob->execute(
            $this->fixture->teacher(),
            null,
            null,
            'course',
            $this->fixture->courseId,
            [],
            false,
            null,
            null,
            $this->fixture->courseId,
        );
    }

    /** And it refuses a recipe key nothing registers, at the queue rather than at drain time. */
    public function testQueueingRefusesARecipeNothingRegisters(): void
    {
        $this->expectException(ValidationException::class);

        $this->fixture->kernel->queueAiJob->execute(
            $this->fixture->teacher(),
            null,
            'acme.course_build',
            'course',
            $this->fixture->courseId,
            [],
            false,
            null,
            null,
            $this->fixture->courseId,
        );
    }

    public function testDrainingRunsTheUnitAndCompletesTheJob(): void
    {
        $task = (new ScriptedAiTask())->willDraft(['prompt' => 'What is tea?']);
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->enableAiFor($task->key());

        $job = $this->queue($task->key());
        $drained = $this->fixture->kernel->runQueuedAiJob->execute($job->id);

        self::assertSame(AiJobStatus::Completed, $drained->status);
        self::assertSame(1, $task->calls);
        self::assertNotNull($drained->finishedAt);
        // The heartbeat moved, which is how a reader tells a build that is still
        // working from one whose process died without saying so.
        self::assertNotNull($drained->updatedAt);
        self::assertSame(1, $this->rows('ai_drafts', ['run_id' => $this->runIdOf($job->id)]));
    }

    /** A preview is not an applied run, on the job as well as on the run. */
    public function testADryRunEndsPreviewedAndNeverCompleted(): void
    {
        $task = (new ScriptedAiTask())->willDraft(['prompt' => 'Never written']);
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->enableAiFor($task->key());

        $job = $this->queue($task->key(), dryRun: true);
        $drained = $this->fixture->kernel->runQueuedAiJob->execute($job->id);

        self::assertSame(AiJobStatus::Previewed, $drained->status);
        self::assertSame(0, $task->calls);
        self::assertSame(0, $this->fixture->env->database->count(Query::from('ai_drafts')));
    }

    /** A redelivered task must not restart a build that is already halfway. */
    public function testARedeliveredDrainIsRefusedRatherThanRestartingTheBuild(): void
    {
        $task = new ScriptedAiTask();
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->enableAiFor($task->key());

        $job = $this->queue($task->key());
        $this->fixture->kernel->runQueuedAiJob->execute($job->id);

        $this->expectException(ConflictException::class);
        $this->fixture->kernel->runQueuedAiJob->execute($job->id);
    }

    /**
     * The handler owns its own retry and therefore does not throw: an
     * already-claimed job is a log line, not an exception the host's runner
     * retries on top of the pipeline's own decisions.
     */
    public function testTheScheduledHandlerLogsAnAlreadyClaimedJobRatherThanThrowing(): void
    {
        $task = new ScriptedAiTask();
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->enableAiFor($task->key());

        $job = $this->queue($task->key());
        $this->fixture->kernel->runQueuedAiJob->execute($job->id);

        $handler = $this->fixture->kernel->registries->scheduledTasks->get(QueueAiJob::TASK_TYPE);
        $line = $handler->run(new \Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun(
            $job->id,
            ['job_id' => $job->id],
            \Shondalai\VidyaLMS\Core\Application\Shared\Principal::guest(),
        ));

        self::assertStringContainsString('already_claimed', $line);
    }

    /**
     * A ceiling that says "not now" parks the job with a real instant and the
     * resume is the SAME row under the same dedupe key.
     */
    public function testACeilingDefersTheJobAndTheResumeIsTheSameRowRatherThanASecondJob(): void
    {
        $task = new ScriptedAiTask();
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->enableAiFor($task->key());
        $this->fixture->kernel->registries->aiPolicies->register(
            ScriptedAiPolicy::deferring(new \DateTimeImmutable('2026-06-02 00:00:00')),
        );

        $job = $this->queue($task->key());
        $drained = $this->fixture->kernel->runQueuedAiJob->execute($job->id);

        self::assertSame(AiJobStatus::Deferred, $drained->status);
        self::assertSame('2026-06-02 00:00:00', $drained->resumeAt?->format('Y-m-d H:i:s'));
        self::assertNull($drained->finishedAt, 'A deferred job is parked, not finished.');
        self::assertSame(1, $this->fixture->env->database->count(Query::from('ai_jobs')));

        // The resume claims the same row. Without the re-open a deferred build
        // would have needed a second job, which is the duplicate the dedupe key
        // exists to prevent.
        $this->fixture->kernel->registries->aiPolicies->replace(ScriptedAiPolicy::allowing('test.defer'));
        $resumed = $this->fixture->kernel->runQueuedAiJob->execute($job->id);

        self::assertSame($job->id, $resumed->id);
        self::assertSame(AiJobStatus::Completed, $resumed->status);
        self::assertSame(1, $this->fixture->env->database->count(Query::from('ai_jobs')));
    }

    /**
     * A resumed job does not re-run the step it already completed.
     *
     * `drive()` iterated the recipe from index zero on every invocation and
     * the job map was write-only on this path: `remember()` was called and
     * `resolve()` never was. The run key carries the resume instant, so the
     * re-run did not collide with the completed unit and a second `ai_runs`
     * row and a second set of drafts were written for work already done.
     */
    public function testAResumedJobDoesNotRunTheStepItAlreadyCompleted(): void
    {
        $task = (new ScriptedAiTask())->willDraft(['prompt' => 'What is tea?']);
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->enableAiFor($task->key());

        $job = $this->queue($task->key());
        $this->fixture->kernel->runQueuedAiJob->execute($job->id);

        self::assertSame(1, $task->calls);
        self::assertSame(1, $this->fixture->env->database->count(Query::from('ai_drafts')));

        // The clock moves, which is what makes this the case the finding is
        // about: the run key folds the instant, so on a resume minutes later
        // the re-run does NOT collide with the completed unit and the ledger's
        // own unique index stops refusing the duplicate.
        $this->fixture->env->clock->set('2026-06-01 09:05:00');

        // The runner is re-entered over the same job, which is what a reclaimed
        // lease and a resumed deferral both do.
        $reloaded = $this->fixture->kernel->aiJobStore->find($job->id);
        self::assertNotNull($reloaded);
        $this->fixture->kernel->aiJobRunner->run($reloaded, $this->fixture->teacher());

        self::assertSame(1, $task->calls, 'the completed step is not run a second time');
        self::assertSame(
            1,
            $this->fixture->env->database->count(Query::from('ai_drafts')),
            'and no second set of drafts is written for work already done',
        );
    }

    /**
     * The rule the import pipeline does not have: a build that spent and then
     * failed records the spend.
     */
    public function testAJobThatSpentAndThenFailedRecordsTheSpendOnTheJobRow(): void
    {
        $task = (new ScriptedAiTask())
            ->willSpend(CostRecord::of(CostRecord::UNIT_TOKENS, 900.0));
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->enableAiFor($task->key());

        $job = $this->queue($task->key());
        $drained = $this->fixture->kernel->runQueuedAiJob->execute($job->id);

        self::assertSame(CostRecord::UNIT_TOKENS, $drained->costUnit);
        self::assertSame(900.0, $drained->costCharged);
    }

    /**
     * A status an operator set is final: a late finish appends a note and leaves
     * their ending alone.
     *
     * The operator ends the stalled job from the admin screen WHILE the process
     * that started it is still alive, which is the only sequence that matters and
     * the only one worth reproducing: the runner is already past its own
     * markRunning by then, and the guarded ending write is what makes their
     * decision final by construction rather than by timing.
     */
    public function testAStatusAnOperatorSetIsFinalAndALateFinishOnlyAppendsANote(): void
    {
        $task = new ScriptedAiTask();
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->enableAiFor($task->key());

        $job = $this->queue($task->key());

        $task->whileRunning(function () use ($job): void {
            $this->fixture->env->database->update('ai_jobs', [
                'status' => AiJobStatus::Failed->value,
                'log' => 'Ended by hand because it looked stuck.',
            ], ['id' => $job->id]);
        });

        $late = $this->fixture->kernel->runQueuedAiJob->execute($job->id);

        self::assertSame(AiJobStatus::Failed, $late->status, 'The status a person set is final.');
        self::assertStringContainsString('Ended by hand', (string) $late->log);
        self::assertStringContainsString('after the job had been marked', (string) $late->log);
    }

    /**
     * An unreviewed draft is decided about rather than aged out, and the reason
     * is a countable code.
     */
    public function testTheStaleDraftSweepDiscardsWithACountableReasonCode(): void
    {
        $task = (new ScriptedAiTask())->willDraft(['prompt' => 'Nobody looked at this']);
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->enableAiFor($task->key());

        $job = $this->queue($task->key());
        $this->fixture->kernel->runQueuedAiJob->execute($job->id);

        $draftId = (int) ($this->fixture->env->database->selectOne(
            Query::from('ai_drafts')->select(['id'])->orderBy('id', Query::SORT_ASC),
        )['id'] ?? 0);
        self::assertGreaterThan(0, $draftId);

        $this->fixture->env->database->update(
            'ai_drafts',
            ['updated_at' => '2020-01-01 00:00:00'],
            ['id' => $draftId],
        );

        $sweep = new AiStaleDraftSweep($this->fixture->kernel->aiDraftStore, $this->fixture->env->clock);
        $result = $sweep->run();

        self::assertSame(1, $result['discarded']);

        $draft = $this->fixture->kernel->aiDraftStore->find($draftId);
        self::assertNotNull($draft);
        self::assertSame(AiDraftState::Discarded, $draft->state);
        self::assertSame(AiStaleDraftSweep::REASON_CODE, $draft->rejectReasonCode);
    }

    /**
     * A build accepted inside an organisation stamps every run row it writes
     * with that organisation, days later and with no session to re-derive it
     * from.
     *
     * The tenant travels as the job's own recorded column rather than as a scope
     * the runner minted, because OrgScope may be minted only by its witnessed
     * factories and a runner is not one of them. What the column does is stamp
     * the rows and narrow the per-organisation reads; the authorisation itself
     * happened at the drain, before the request object existed.
     */
    public function testAnOrganisationBuildStampsEveryRunRowWithItsTenant(): void
    {
        $task = (new ScriptedAiTask())->willDraft(['prompt' => 'Org build']);
        $this->fixture->kernel->registries->aiTasks->register($task);
        $this->enableAiFor($task->key());

        $job = $this->queue($task->key());
        // Stamped on the row the way QueueAiJob stamps it from an authorised
        // scope, which is the only place an organisation may come from.
        $this->fixture->env->database->update('ai_jobs', ['organisation_id' => 77], ['id' => $job->id]);

        $reloaded = $this->fixture->kernel->aiJobStore->find($job->id);
        self::assertNotNull($reloaded);
        self::assertSame(77, $reloaded->organisationId);

        $this->fixture->kernel->aiJobRunner->run($reloaded, $this->fixture->teacher());

        $runs = $this->fixture->kernel->aiRunStore->forJob($job->id);
        self::assertNotSame([], $runs);

        foreach ($runs as $run) {
            self::assertSame(77, $run->organisationId);
        }
    }

    private function queue(string $taskKey, bool $dryRun = false): \Shondalai\VidyaLMS\Core\Domain\Ai\AiJob
    {
        return $this->fixture->kernel->queueAiJob->execute(
            $this->fixture->teacher(),
            $taskKey,
            null,
            'course',
            $this->fixture->courseId,
            [],
            $dryRun,
            null,
            null,
            $this->fixture->courseId,
        );
    }

    private function runIdOf(int $jobId): int
    {
        $runs = $this->fixture->kernel->aiRunStore->forJob($jobId);

        return $runs === [] ? 0 : $runs[0]->id;
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

    /**
     * The four levels, turned on as an administrator would.
     */
    private function enableAiFor(string $taskKey): void
    {
        $now = '2026-06-01 09:00:00';
        $database = $this->fixture->env->database;

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
}
