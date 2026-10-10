<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJobRepository;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportRowProblemRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Registry\Importer\DeclaresSourceTypes;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportLoadOrder;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportSourceKey;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\PreflightsBeforeWriting;
use Shondalai\VidyaLMS\Core\Registry\Importer\ProgressiveImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\StagedImporterInterface;

/**
 * Drives one existing job through its importer and owns every status
 * transition: running, then completed, previewed or failed.
 *
 * Two rules govern what it writes:
 *
 * - Nothing is rolled back. An importer that failed after creating eleven of
 *   twenty lessons has created eleven lessons, and the job says so. The
 *   import map is what makes that safe: a re-run updates those eleven instead
 *   of duplicating them.
 * - A dry run ends as "previewed", never "completed", because a preview is
 *   not an applied import.
 * - A status an operator set is final. AbandonImportJob is a person saying a
 *   stalled run is over, and the process they ended is often still alive: it
 *   finishes an hour later and would write its own ending over theirs, note
 *   and all. So every write here first asks whether the job is still running,
 *   and a run that lost its row records what it did as an appended note
 *   instead, leaving the status and the log the person gave it alone.
 *
 * The progress write is also the job's heartbeat: it moves updated_at, which
 * is how a reader tells a run that is still working from one whose process
 * died without saying so (see ImportJob::hasStalled()). A run whose progress
 * is counting rather than noting writes no log at all, so the recorder gets a
 * second callback that moves the heartbeat and nothing else: a dry run and a
 * long counting phase are silent, not dead. Nothing marks a job failed on its
 * behalf.
 *
 * Not a use case: it holds no authorization of its own. The use cases that
 * call it (RunImportJob, RunQueuedImport) require MANAGE first.
 */
final class ImportRunner
{
    public function __construct(
        private readonly ImportJobRepository $jobs,
        /**
         * Where a bad row goes. Optional so that the three shipped course
         * importers, and every test built around them, keep working with a
         * runner that has nowhere to put row problems: an importer that
         * reports none is unaffected either way.
         */
        private readonly ?ImportRowProblemRepository $rowProblems = null,
        /**
         * The shared import map, narrowed to this run's organisation for the
         * length of the run. Optional so every caller built before the tenant
         * rule still constructs; a runner with no map narrows nothing and the
         * map's own rule then refuses every reserved source type, which is the
         * safe direction.
         */
        private readonly ?ImportMap $map = null,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function run(
        int $jobId,
        ImporterInterface $importer,
        ?string $sourcePath,
        array $options,
        Principal $principal,
    ): ImportJob {
        $dryRun = ($options['dry_run'] ?? false) === true;

        // The tenant rule, checked before the first row rather than on row
        // nine thousand. An importer that mints reserved organisation source
        // types composes every key as '{organisation_id}:{foreign key}', and
        // ImportMap refuses one that does not; without an organisation on the
        // job there is no id to compose with, so the run would write four
        // hundred rows and then refuse the four hundred and first.
        $refusal = $this->tenantRefusal($jobId, $importer);

        if ($refusal !== null) {
            $this->jobs->markRunning($jobId);
            $recorder = new ImportRecorder($jobId, $dryRun);
            $recorder->note(ImportNote::ERROR, $refusal);
            $this->end($jobId, $recorder->result(), ImportJob::STATUS_FAILED, $refusal);

            return $this->reload($jobId);
        }

        // The run context's organisation, read off the job once and carried
        // from here into the recorder and into the import map. Every place
        // that needs the tenant takes it from this variable, so there is one
        // answer to "which organisation is this run" rather than one per
        // consumer.
        $organisationId = $this->jobs->find($jobId)?->organisationId;

        $this->jobs->markRunning($jobId);

        // A re-run reports its own rows and not the last run's. Row problems
        // are appended rather than upserted, because two identical problems on
        // two rows are two problems, so the previous run's report is cleared
        // rather than merged with.
        $this->rowProblems?->forgetJob($jobId);

        // Set once the job has left the running status under this process,
        // which is an operator ending it from the admin screen. From then on
        // the row is theirs and this run stops writing to it.
        $taken = false;

        /**
         * Row reports the sink refused, reported once at the end rather than
         * once per row: a width refusal is refused for every row of its kind.
         *
         * @var list<string> $reportRefusals
         */
        $reportRefusals = [];

        $recorder = new ImportRecorder(
            $jobId,
            $dryRun,
            function (ImportRecorder $recorder) use ($jobId, &$taken): void {
                if ($taken || $this->wasEndedByHand($jobId)) {
                    $taken = true;

                    return;
                }

                $partial = $recorder->result();
                $this->jobs->recordProgress($jobId, $partial->log(), $partial->totals);
            },
            function () use ($jobId, &$taken): void {
                if ($taken || $this->wasEndedByHand($jobId)) {
                    $taken = true;

                    return;
                }

                $this->jobs->touch($jobId);
            },
            function (RowProblem $problem) use ($jobId, &$taken, &$reportRefusals): void {
                // The same two questions the other two callbacks ask, and for
                // the same reason: a job an operator ended is theirs, and a
                // run that kept appending row problems to it left a job whose
                // status said "failed by hand" carrying five rows this run
                // wrote afterwards.
                if ($taken || $this->wasEndedByHand($jobId)) {
                    $taken = true;

                    return;
                }

                // One refused report may not cost the run. The insert is the
                // only write in the pipeline whose content is somebody else's
                // CSV, so it is the one most likely to be refused by a strict
                // server on a width or a character set, and a row problem that
                // cannot be stored is a report entry lost, not an import lost:
                // the row itself was already dealt with before this was
                // called. The loss is noted once so it is not silent.
                try {
                    $this->rowProblems?->append($jobId, [$problem->toArray()]);
                } catch (\Throwable $refused) {
                    $reportRefusals[] = ImportProblem::describe($refused);
                }
            },
            $organisationId,
        );

        try {
            // Both passes run with the map narrowed to this run's
            // organisation, so a reserved source key is checked against the
            // tenant the job was stamped with rather than against the shape of
            // its own prefix.
            $this->within($organisationId, function () use (
                $dryRun,
                $importer,
                $sourcePath,
                $options,
                $principal,
                $recorder,
            ): void {
                // The dry pass, for an importer that asked for one. It runs
                // before the real pass rather than instead of it: a roster
                // whose references do not resolve is reported whole before a
                // single row is written, which is what "referential integrity
                // is validated before anything is written" means in practice.
                // Its problems are counted and reported and nothing durable is
                // written, so the two passes report into one sink and the
                // operator reads one story.
                if (!$dryRun && $importer instanceof PreflightsBeforeWriting) {
                    $preflight = $recorder->preflight();
                    $this->drive($importer, $sourcePath, ['dry_run' => true] + $options, $principal, $preflight);
                    $recorder->absorbPreflight($preflight);
                }

                $this->drive($importer, $sourcePath, $options, $principal, $recorder);
            });
        } catch (\Throwable $failure) {
            $reason = ImportProblem::describe($failure);
            $recorder->note(ImportNote::ERROR, $reason);
            $recorder->summariseFiles();
            $this->end($jobId, $recorder->result(), ImportJob::STATUS_FAILED, $reason);

            return $this->reload($jobId);
        }

        $recorder->summariseFiles();
        $this->noteRefusedRowReports($recorder, $reportRefusals);
        $this->noteAbsentRowReport($recorder);
        $result = $recorder->result();

        $this->end($jobId, $result, match (true) {
            $dryRun => ImportJob::STATUS_PREVIEWED,
            // Finishing and finishing cleanly are different terminal states,
            // and the predicate is the rows rather than a note an importer has
            // to remember to write. An importer reporting twenty thousand
            // rejected rows through the published row model and no error note
            // used to end green, with a failed-rows download full of work
            // nobody was told about.
            $result->finishedWithProblems() => ImportJob::STATUS_COMPLETED_WITH_PROBLEMS,
            default => ImportJob::STATUS_COMPLETED,
        });

        return $this->reload($jobId);
    }

    /**
     * Runs one pass with the import map narrowed to the run's organisation.
     *
     * A runner built with no map runs the body unnarrowed, which leaves the
     * map's own rule refusing every reserved source type: a run whose tenant
     * cannot be established may not write an organisation-scoped mapping.
     *
     * @param callable(): void $body
     */
    private function within(?int $organisationId, callable $body): void
    {
        if ($this->map === null) {
            $body();

            return;
        }

        $this->map->duringRun($organisationId, $body);
    }

    /**
     * Says once that the row report lost entries, when the sink refused them.
     */
    /**
     * @param list<string> $refusals
     */
    private function noteRefusedRowReports(ImportRecorder $recorder, array $refusals): void
    {
        if ($refusals === []) {
            return;
        }

        $recorder->note(ImportNote::WARNING, sprintf(
            '%d row problem(s) could not be recorded against this job, so the row report below is incomplete.'
                . ' The rows themselves were dealt with as the log says. First refusal: %s',
            \count($refusals),
            $refusals[0],
        ));
    }

    /**
     * Says so when a run finishes with problems and has no rows to show for
     * it.
     *
     * The status means "rows were rejected" in the published contract, and a
     * run that failed in a way costing no single row (a source that would not
     * open, a stage that threw after its rows were written) still earns it
     * from the error note. Without this line the operator is handed a row
     * report with nothing in it, which reads as "this import went perfectly",
     * and is left to guess that the story is in the log.
     */
    private function noteAbsentRowReport(ImportRecorder $recorder): void
    {
        if ($recorder->rejectedRows() > 0 || !$recorder->result()->hasErrors()) {
            return;
        }

        $recorder->note(
            ImportNote::WARNING,
            'This run finished with problems that were not row-level, so its row report is empty and the'
                . ' failed-rows download has nothing in it. What went wrong is in this log.',
        );
    }

    /**
     * The registry-miss direction for the Importer registry, implemented.
     *
     * A stored job whose importer key resolves to nothing is **left pending
     * and reported**, never marked failed. The reason is the one the scheduled
     * task queue learned the hard way: a job marked failed because an
     * extension was not registered at that moment is a job whose work is
     * destroyed, and the usual cause is temporary. An extension that failed to
     * install, a plugin disabled during a deploy, a Pro package not yet
     * activated: in every one of them the right answer is that the run has not
     * happened yet, which is exactly what pending means.
     *
     * The report is a note on the job, so the operations screen shows why a
     * job has been sitting there, and the status is untouched, so installing
     * the missing extension and running it again does the work.
     */
    public function reportUnresolvedImporter(int $jobId, string $importerKey, string $reason): ImportJob
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $job = $this->jobs->find($jobId) ?? throw EntityNotFoundException::for('Import job', $jobId);

        $notes = ImportNote::parseLog($job->log);
        $notes[] = ImportNote::error(sprintf(
            'Nothing registered answers to the importer "%s", so this job has not run and is still waiting.'
                . ' It is deliberately not marked failed: the usual cause is an extension that is not installed'
                . ' or not enabled, and running it again once it is will do the work. %s',
            $importerKey,
            $reason,
        ));

        // recordProgress is the one write that leaves the status alone, which
        // is precisely what is wanted: the job stays pending.
        $this->jobs->recordProgress(
            $jobId,
            implode("\n", array_map(static fn (ImportNote $note): string => $note->line(), $notes)),
            $job->totals ?? [],
        );

        return $this->reload($jobId);
    }

    /**
     * Why this importer may not run against this job, or null when it may.
     *
     * The only rule here is the tenant one, and it is the only one that can be
     * decided before a source is opened: an importer minting reserved
     * organisation source types needs an organisation, and a job that carries
     * none cannot give it one. An importer whose source types are its own is
     * unaffected, which is every importer that shipped before this rule.
     */
    private function tenantRefusal(int $jobId, ImporterInterface $importer): ?string
    {
        if (!$importer instanceof DeclaresSourceTypes) {
            return null;
        }

        $reserved = array_values(array_filter($importer->sourceTypes(), ImportSourceKey::isReserved(...)));

        if ($reserved === [] || $this->jobs->find($jobId)?->organisationId !== null) {
            return null;
        }

        return sprintf(
            'This importer writes organisation-scoped objects (%s), and every key it records is composed as'
                . ' "{organisation_id}:{foreign key}". This job names no organisation, so there is no tenant to'
                . ' compose with. Start it from the organisation\'s own import screen, where the organisation'
                . ' comes from the run context rather than from the file.',
            implode(', ', $reserved),
        );
    }

    /**
     * One pass over the source, staged or not.
     *
     * The load order is applied here rather than in each mapper, so a source
     * that carries organisations, people and enrolments loads them in that
     * order whatever order it declared them in.
     *
     * @param array<string, mixed> $options
     */
    private function drive(
        ImporterInterface $importer,
        ?string $sourcePath,
        array $options,
        Principal $principal,
        ImportRecorder $recorder,
    ): void {
        $source = $sourcePath ?? '';

        if ($importer instanceof StagedImporterInterface) {
            foreach (ImportLoadOrder::sort($importer->stages($source, $options)) as $stage) {
                $importer->importStage($stage, $source, $options, $principal, $recorder);
            }

            return;
        }

        if ($importer instanceof ProgressiveImporterInterface) {
            $importer->importWithProgress($source, $options, $principal, $recorder);

            return;
        }

        $recorder->absorb(ImportResult::fromArray($importer->import($source, $options, $principal)));
    }

    /**
     * Ends the run, unless an operator ended the job first.
     *
     * A stalled run is ended by hand from the admin screen while the process
     * that started it is still alive: a run blocked on a slow source finishes
     * an hour later and writes its own ending over the operator's. The
     * operator looked at the row and decided; this process did not, and it
     * cannot tell whether they have already acted on the decision. So a job
     * that is no longer running is left with the status and the log the
     * person gave it, and the late finish is appended to it instead.
     */
    private function end(int $jobId, ImportResult $result, string $status, ?string $reason = null): void
    {
        // The write itself is the check: each of these updates matches only a
        // job still marked running, so an operator who ended the job between a
        // read here and the write cannot be overwritten. A guarded update is
        // the same device the completion engine uses for its exactly-once
        // transition, and it makes the operator's decision final by
        // construction rather than by timing.
        $won = $this->jobs->finishIfRunning($jobId, $status, $result->log(), $result->totals);

        if ($won) {
            return;
        }

        $job = $this->jobs->find($jobId);

        if ($job !== null) {
            $this->recordLateFinish($job, $result, $status, $reason);
        }
    }

    /**
     * Writes what the late run did onto the job the operator ended, without
     * touching their status: recordProgress() is the one write that leaves it
     * alone. The totals are this run's own, because they are what the
     * database now holds, and the operator's snapshot was this same run's
     * counts one flush earlier.
     *
     * One note is appended, not the run's remaining log. The operator is
     * reading that row, and a migration that noted forty thousand lessons
     * after they ended it would bury their decision under its own story. The
     * totals say what it did; the reason says why it stopped, when it failed.
     */
    private function recordLateFinish(ImportJob $job, ImportResult $result, string $status, ?string $reason): void
    {
        $notes = ImportNote::parseLog($job->log);
        $notes[] = ImportNote::warning(sprintf(
            'The process running this import %s after the job had been marked "%s" here, so it left that'
                . ' status and the notes above as they were. What the run had done when it ended: %s.'
                . ' Nothing is rolled back, so running the same source again updates what exists rather'
                . ' than duplicating it.',
            match ($status) {
                ImportJob::STATUS_PREVIEWED => 'finished its preview',
                ImportJob::STATUS_FAILED => 'failed',
                default => 'finished',
            },
            $job->status,
            self::counted($result),
        ));

        if ($reason !== null) {
            $notes[] = ImportNote::error($reason);
        }

        $this->jobs->recordProgress(
            $job->id,
            implode("\n", array_map(static fn (ImportNote $note): string => $note->line(), $notes)),
            $result->totals,
        );
    }

    /**
     * Whether the job has left the running status under this process, which
     * nothing but an operator's decision does.
     */
    private function wasEndedByHand(int $jobId): bool
    {
        $job = $this->jobs->find($jobId);

        return $job !== null && $job->status !== ImportJob::STATUS_RUNNING;
    }

    private static function counted(ImportResult $result): string
    {
        $parts = [];

        foreach ($result->totals as $what => $count) {
            $parts[] = sprintf('%s %d', $what, $count);
        }

        return $parts === [] ? 'nothing' : implode(', ', $parts);
    }

    private function reload(int $jobId): ImportJob
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        return $this->jobs->find($jobId) ?? throw EntityNotFoundException::for('Import job', $jobId);
    }
}
