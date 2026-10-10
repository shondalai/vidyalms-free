<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiJob;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiJobRepository;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiJobStatus;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiJobMapRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRecipeInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRecipeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRecipeStep;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\CancellationSignal;

/**
 * Drives one existing job through the pipeline and owns every status transition.
 *
 * It inherits ImportRunner's three rules verbatim, because the failures they
 * were written for are the same failures here:
 *
 * - **Nothing is rolled back.** A build that generated eleven of twenty lessons
 *   has generated eleven, and the job says so. The job map is what makes that
 *   safe: a re-run updates those eleven instead of duplicating them.
 * - **A dry run ends previewed, never completed**, because a preview is not an
 *   applied run.
 * - **A status an operator set is final.** Every ending write asks whether the
 *   job is still running, and a run that lost its row appends a note rather than
 *   overwriting the operator's decision, note and all.
 *
 * And it adds the two rules money and regulation force:
 *
 * - **A run that spent and then failed records the spend**, on the job and on
 *   every run row it produced. An import that fails costs nothing; an AI job that
 *   fails has already paid the upstream tokens, and a job log saying only
 *   "failed" leaves a site owner unable to explain their bill.
 * - **A deferred job resumes rather than fails.** A ceiling policy defers, the
 *   row is parked with a real resume instant under the same dedupe key, and the
 *   forty drafts a two hundred item build already made stay under a status that
 *   does not read as a breakage.
 *
 * The progress write is also the heartbeat: it moves updated_at, which is how a
 * reader tells a build that is still working from one whose process died without
 * saying so. A phase with nothing to say moves the heartbeat alone.
 *
 * **Not a use case: it holds no authorisation of its own**, exactly as
 * ImportRunner holds none. RunQueuedAiJob authorises before calling it.
 *
 * **A job may run several tasks, through a registered recipe.** A job row holds
 * one task key and a flagship build runs three, and the two ways out were a core
 * orchestrator hardcoding the sequence, which makes a fourth step a core edit, or
 * a primitive. It is a primitive: this walks the recipe calling one task per
 * step, fanning out over the previous step's drafts.
 *
 * **There is no streaming in this path.** Every unit is a buffered call small
 * enough for one, so progress comes from the log rather than from tokens
 * appearing, and the bytes-on-the-wire settlement case cannot arise on a path
 * that does not stream.
 */
final class AiJobRunner
{
    /** The counter key for units a recipe step produced nothing for. */
    public const TOTAL_SKIPPED = 'skipped';

    public function __construct(
        private readonly AiJobRepository $jobs,
        private readonly AiRunPipeline $pipeline,
        private readonly AiRecipeRegistry $recipes,
        private readonly AiEnablementState $enablement,
        private readonly ?AiJobMapRepository $map = null,
    ) {
    }

    public function run(AiJob $job, Principal $principal): AiJob
    {
        $this->jobs->markRunning($job->id);

        // Set once the job has left the running status under this process, which
        // is an operator ending it from the admin screen. From then on the row is
        // theirs and this run stops writing to it.
        $taken = false;
        $recorder = new AiRunRecorder(
            $job->id,
            $job->dryRun,
            function (AiRunRecorder $recorder) use ($job, &$taken): void {
                if ($taken || $this->wasEndedByHand($job->id)) {
                    $taken = true;

                    return;
                }

                $this->jobs->recordProgress($job->id, $recorder->log(), $recorder->totals());
            },
            function () use ($job, &$taken): void {
                if ($taken || $this->wasEndedByHand($job->id)) {
                    $taken = true;

                    return;
                }

                $this->jobs->touch($job->id);
            },
            fn (): bool => $this->enablement->stopped(),
        );

        $stop = CancellationSignal::from(static fn (): bool => $recorder->stopRequested());
        $deferUntil = null;
        $stopped = false;

        try {
            [$deferUntil, $stopped] = $this->drive($job, $principal, $recorder, $stop);
        } catch (\Throwable $failure) {
            $recorder->note(ImportNote::ERROR, $failure->getMessage());
            $this->recordSpend($job, $recorder);
            $this->end($job, $recorder, AiJobStatus::Failed);

            return $this->reload($job->id);
        }

        // The spend is written BEFORE the ending, so a job that failed at item
        // forty carries what it spent whichever ending follows.
        $this->recordSpend($job, $recorder);

        if ($stopped) {
            $recorder->note(
                ImportNote::WARNING,
                'The site-wide stop was set while this build was running, so it halted between units and kept'
                    . ' everything it had already produced.',
            );
            $this->end($job, $recorder, AiJobStatus::Cancelled);

            return $this->reload($job->id);
        }

        if ($deferUntil !== null) {
            $recorder->note(ImportNote::INFO, sprintf(
                'A ceiling deferred the rest of this build to %s. What it has produced so far is kept and the'
                    . ' scheduled task re-arms itself under the same key, so the resume is this row rather than'
                    . ' a second job.',
                $deferUntil->format('Y-m-d H:i:s'),
            ));
            $this->defer($job, $recorder, $deferUntil);

            return $this->reload($job->id);
        }

        $this->end($job, $recorder, $job->dryRun ? AiJobStatus::Previewed : AiJobStatus::Completed);

        return $this->reload($job->id);
    }

    /**
     * Runs the job's work, one unit at a time.
     *
     * @return array{0: \DateTimeImmutable|null, 1: bool} the deferral instant and
     *                                                    whether the stop halted
     *                                                    the build
     */
    private function drive(
        AiJob $job,
        Principal $principal,
        AiRunRecorder $recorder,
        CancellationSignal $stop,
    ): array {
        $steps = $this->stepsOf($job);
        $previousDrafts = [];

        foreach ($steps as $index => $step) {
            if ($stop->isCancelled()) {
                return [null, true];
            }

            // A resumed job used to start at index zero and re-run every step
            // it had already completed, because the map was write-only on this
            // path: remember() was called and resolve() never was. The run key
            // includes the resume instant, so the re-run did not collide with
            // the completed one and a second ai_runs row and a second set of
            // drafts were written for work already done.
            $done = $this->draftsAlreadyMade($job, $step, $index);

            if ($done !== []) {
                $previousDrafts = $done;
                $recorder->note(ImportNote::INFO, sprintf(
                    'Step %d (%s) was completed by an earlier attempt at this job and is not run again.',
                    $index,
                    $step->taskKey,
                ));

                continue;
            }

            if ($index > 0 && $step->fansOutOverDraftKind !== null && $previousDrafts === []) {
                if (!$step->skippableWhenEmpty) {
                    $recorder->note(ImportNote::ERROR, sprintf(
                        'Step %d (%s) fans out over "%s" and the previous step produced none, and the step is not'
                            . ' skippable.',
                        $index,
                        $step->taskKey,
                        $step->fansOutOverDraftKind,
                    ));

                    throw new \RuntimeException('A required recipe step had nothing to fan out over.');
                }

                $recorder->counted(self::TOTAL_SKIPPED);
                $recorder->note(ImportNote::INFO, sprintf(
                    'Step %d (%s) skipped: the previous step produced no "%s" draft to fan out over.',
                    $index,
                    $step->taskKey,
                    $step->fansOutOverDraftKind,
                ));

                continue;
            }

            $report = $this->pipeline->run(
                $this->requestFor($job, $principal, $step),
                $stop,
            );

            $recorder->absorb($report->log);
            $recorder->spent($report->spent);
            $previousDrafts = $report->draftIds;

            foreach ($report->draftIds as $draftId) {
                $recorder->counted(AiRunRecorder::TOTAL_DRAFTED);
                $this->map?->remember(
                    $job->id,
                    $step->taskKey,
                    $this->unitKey($job, $index),
                    'ai_draft',
                    $draftId,
                );
            }

            if ($report->draftIds === []) {
                $recorder->counted(AiRunRecorder::TOTAL_EMPTY);
            }

            if ($report->wasRefused()) {
                $recorder->counted(AiRunRecorder::TOTAL_REFUSED);
            }

            if ($report->wasDeferred()) {
                return [$report->deferUntil, false];
            }

            $recorder->beat();
        }

        return [null, $stop->isCancelled()];
    }

    /**
     * The drafts an earlier attempt at this job already produced for this
     * step, read back through the job map.
     *
     * The map's own docblock says it is what makes a resumed or re-run build
     * an update rather than a second set of drafts, and the whole reason
     * `resolve()` exists. The unit key is the same one `remember()` writes, so
     * the two cannot disagree about what a step is.
     *
     * @return list<int>
     */
    private function draftsAlreadyMade(AiJob $job, AiRecipeStep $step, int $index): array
    {
        if ($this->map === null) {
            return [];
        }

        $entry = $this->map->resolve($job->id, $step->taskKey, $this->unitKey($job, $index));

        return $entry === null ? [] : [$entry->localId];
    }

    /**
     * The key one step of one job is remembered under.
     *
     * Derived in one place so the write and the read cannot drift: the write
     * used a literal expression inline and nothing read it at all, which is
     * how a write-only map goes unnoticed.
     */
    private function unitKey(AiJob $job, int $index): string
    {
        return (string) $job->scopeId . ':' . $index;
    }

    /**
     * The steps this job runs: a recipe's own list, or the single step a
     * task-keyed job is.
     *
     * A job naming a recipe nothing registers refuses at the queue rather than
     * here, so an unresolvable recipe at drain time is a package removed between
     * the two, and it fails the job with the key named rather than silently
     * completing with nothing done.
     *
     * @return list<AiRecipeStep>
     */
    private function stepsOf(AiJob $job): array
    {
        if ($job->taskKey !== null) {
            return [AiRecipeStep::first($job->taskKey)];
        }

        $key = (string) $job->recipeKey;

        if (!$this->recipes->has($key)) {
            throw new \RuntimeException(sprintf(
                'No AI recipe is registered under "%s", so this build cannot run. The package that supplied it'
                    . ' is the thing to reinstall.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $key,
            ));
        }

        $recipe = $this->recipes->get($key);

        if (!$recipe instanceof AiRecipeInterface) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException(sprintf('The provider registered under "%s" is not an AI recipe.', $key));
        }

        return $recipe->steps();
    }

    private function requestFor(AiJob $job, Principal $principal, AiRecipeStep $step): AiRunRequest
    {
        return AiRunRequest::create(
            $principal,
            $step->taskKey,
            AiTaskRequest::create(
                $job->scopeType,
                $job->scopeId,
                $job->options,
            ),
            $job->placementKey,
            $job->scopeType,
            $job->scopeId,
            $job->scopeType === 'course' ? $job->scopeId : null,
            null,
            null,
            $job->dryRun,
            'en-GB',
            $job->id,
            // The tenant is the job's own recorded column, carried rather than
            // re-minted: OrgScope may be minted only by its witnessed factories
            // and a runner is not one of them, while every run row this build
            // writes still has to carry the organisation it belongs to.
        )->recordedTenant($job->organisationId);
    }

    private function recordSpend(AiJob $job, AiRunRecorder $recorder): void
    {
        $unit = $recorder->singleUnit();

        if ($unit === null) {
            return;
        }

        $this->jobs->recordSpend($job->id, $unit, null, $recorder->spendIn($unit));
    }

    /**
     * Ends the run, unless an operator ended the job first.
     *
     * The write itself is the check: finishIfRunning matches only a job still
     * marked running, so an operator who ended it between a read here and the
     * write cannot be overwritten.
     */
    private function end(AiJob $job, AiRunRecorder $recorder, AiJobStatus $status): void
    {
        if ($this->jobs->finishIfRunning($job->id, $status, $recorder->log(), $recorder->totals())) {
            return;
        }

        $this->recordLateFinish($job, $recorder, $status);
    }

    private function defer(AiJob $job, AiRunRecorder $recorder, \DateTimeImmutable $resumeAt): void
    {
        if ($this->jobs->deferIfRunning($job->id, $resumeAt, $recorder->log(), $recorder->totals())) {
            return;
        }

        $this->recordLateFinish($job, $recorder, AiJobStatus::Deferred);
    }

    /**
     * Writes what the late run did onto the job the operator ended, without
     * touching their status: recordProgress() is the one write that leaves it
     * alone.
     *
     * One note is appended, not the run's remaining log. The operator is reading
     * that row, and a build that noted forty drafts after they ended it would
     * bury their decision under its own story.
     */
    private function recordLateFinish(AiJob $job, AiRunRecorder $recorder, AiJobStatus $status): void
    {
        $current = $this->jobs->find($job->id);

        if ($current === null) {
            return;
        }

        $notes = ImportNote::parseLog($current->log);
        $notes[] = ImportNote::warning(sprintf(
            'The process running this build %s after the job had been marked "%s" here, so it left that status'
                . ' and the notes above as they were. Nothing is rolled back, so what it produced still exists'
                . ' and re-running the same build updates it rather than duplicating it.',
            match ($status) {
                AiJobStatus::Previewed => 'finished its preview',
                AiJobStatus::Failed => 'failed',
                AiJobStatus::Deferred => 'was deferred by a ceiling',
                AiJobStatus::Cancelled => 'was stopped',
                default => 'finished',
            },
            $current->status->value,
        ));

        $this->jobs->recordProgress(
            $job->id,
            implode("\n", array_map(static fn (ImportNote $note): string => $note->line(), $notes)),
            $recorder->totals(),
        );
    }

    private function wasEndedByHand(int $jobId): bool
    {
        $job = $this->jobs->find($jobId);

        return $job !== null && $job->status !== AiJobStatus::Running;
    }

    private function reload(int $jobId): AiJob
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        return $this->jobs->find($jobId) ?? throw EntityNotFoundException::for('AI job', $jobId);
    }
}
