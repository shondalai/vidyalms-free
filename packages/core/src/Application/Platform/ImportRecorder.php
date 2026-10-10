<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Registry\Importer\ImportProgress;
use Shondalai\VidyaLMS\Core\Registry\Importer\KnowsItsOrganisation;
use Shondalai\VidyaLMS\Core\Registry\Importer\RecordsRowProblems;

/**
 * The state of one import run: what has been counted, what has been noted,
 * and whether anything is allowed to be written.
 *
 * Because the recorder holds the run rather than the importer holding it, a
 * failure loses nothing. RunImportJob reads result() whether the importer
 * returned or threw, so partial work is recorded honestly instead of
 * disappearing with the exception.
 *
 * The flush callback, when given, updates the job row while the import is
 * still running. It fires on every one of the first EAGER_NOTES notes, which
 * covers an ordinary import entirely, and then on an interval that doubles
 * from BATCH_NOTES up to MAX_BATCH_NOTES.
 *
 * The doubling is the point. A flush rewrites the whole log, so flushing
 * every tenth note of a hundred thousand writes the log ten thousand times
 * and each write is longer than the last: the cost of a big migration grows
 * with the square of its notes. Backing off keeps the total work close to
 * linear while the ceiling keeps the heartbeat moving, and the caller writes
 * the final state itself, so nothing is lost by it.
 *
 * Counting is progress too, and it is the kind that says nothing. An importer
 * reading two hundred thousand rows before it writes a word, and a dry run
 * that only counts what it would create, emit no notes at all: a recorder
 * that moved the heartbeat only on a note would leave a healthy run looking
 * like one whose process died (see ImportJob::hasStalled()). The touch
 * callback is for that. It moves the heartbeat and nothing else, so it costs
 * one column write rather than a rewritten log, and it fires on counts the
 * flush never sees.
 */
final class ImportRecorder implements ImportProgress, RecordsRowProblems, KnowsItsOrganisation
{
    /** Notes flushed one by one, which covers an ordinary import entirely. */
    private const EAGER_NOTES = 50;

    /** After that, the first interval between flushes. */
    private const BATCH_NOTES = 10;

    /** The interval doubles no further than this, so the job keeps a heartbeat. */
    private const MAX_BATCH_NOTES = 500;

    /**
     * One heartbeat per this many counts: far more often than the quarter of
     * an hour that makes a run look dead, and far less often than once a row.
     */
    private const COUNTS_PER_HEARTBEAT = 100;

    /** @var array<string, int> */
    private array $totals = [];

    /** @var list<ImportNote> */
    private array $notes = [];

    /** @var (callable(self): void)|null */
    private $flush;

    /** @var (callable(): void)|null */
    private $touch;

    /** @var (callable(RowProblem): void)|null */
    private $rowProblems;

    /**
     * Per file: total rows reported, and how many of each outcome.
     *
     * @var array<string, array<string, int>>
     */
    private array $files = [];

    /** The order the files were first seen in, which is the order of the zip. */
    private bool $summarised = false;

    /** The note count the next flush waits for, once the eager phase is over. */
    private int $flushAt = self::EAGER_NOTES + self::BATCH_NOTES;

    private int $interval = self::BATCH_NOTES;

    private int $counts = 0;

    /**
     * Rows this run reported through rowProblem() and rejected, which is what
     * decides whether the job finished cleanly. Counted here rather than
     * derived from the totals, because "skipped" is also a totals key
     * importers use for things that are not rows.
     */
    private int $rejectedRows = 0;

    /**
     * @param (callable(self): void)|null       $flush
     * @param (callable(): void)|null           $touch
     * @param (callable(RowProblem): void)|null $rowProblems where a bad row is recorded, one at a time
     */
    public function __construct(
        private readonly int $jobId = 0,
        private readonly bool $dryRun = false,
        ?callable $flush = null,
        ?callable $touch = null,
        ?callable $rowProblems = null,
        /**
         * The organisation stamped on the job by the run context, or null for
         * a site-wide run. It is what an organisation-scoped mapper composes
         * its source keys with, and it arrives here so that the mapper never
         * has to read it out of its own options map, which is the caller's
         * payload and the one place the design says it may not come from.
         */
        private readonly ?int $organisationId = null,
    ) {
        $this->flush = $flush;
        $this->touch = $touch;
        $this->rowProblems = $rowProblems;
    }

    public function jobId(): int
    {
        return $this->jobId;
    }

    public function organisationId(): ?int
    {
        return $this->organisationId;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function note(string $level, string $message): void
    {
        $this->add(ImportNote::of($level, $message));
    }

    public function counted(string $what, int $delta = 1): void
    {
        if ($what === '') {
            return;
        }

        $this->totals[$what] = ($this->totals[$what] ?? 0) + $delta;
        $this->counts++;

        if ($this->touch !== null && $this->counts % self::COUNTS_PER_HEARTBEAT === 0) {
            ($this->touch)();
        }
    }

    /**
     * Folds a result an importer returned in one piece into this run, so the
     * completion path is the same whether the importer streamed or not.
     */
    public function absorb(ImportResult $result): void
    {
        foreach ($result->totals as $what => $count) {
            $this->counted($what, $count);
        }

        foreach ($result->notes as $note) {
            $this->add($note);
        }
    }

    /**
     * One bad row, written straight through rather than buffered.
     *
     * Immediately, and that is the point: a process killed on row nine
     * thousand of forty must leave behind what it already knew, which is the
     * same rule the import map is written under. One insert per bad row is
     * linear in the bad rows and is paid only by imports that have them; the
     * quadratic cost the separate table exists to avoid is in rewriting the
     * whole log, not in appending a row.
     *
     * A dry run records nothing durable, because a preview writes nothing at
     * all, neither through a use case nor into the map. Its problems still
     * count into the totals and the per-file summary, which is what makes the
     * automatic dry pass worth running: the operator sees what would go wrong
     * before anything is written.
     */
    public function rowProblem(RowProblem $problem): void
    {
        $this->countRow($problem->file, $problem->outcome, $problem->wasRejected());

        if ($this->dryRun || $this->rowProblems === null) {
            return;
        }

        ($this->rowProblems)($problem);
    }

    public function rowOutcome(string $file, string $outcome): void
    {
        $this->countRow($file, $outcome);
    }

    /**
     * A dry sibling of this run, for the pass that happens before anything is
     * written.
     *
     * A second recorder rather than the same one, and the arithmetic is the
     * reason: the dry pass counts what it *would* create and the real pass
     * counts what it *did*, so one recorder taking both would report a
     * thousand row file as two thousand rows. The heartbeat is shared, because
     * a preflight over forty thousand rows is exactly the kind of long silent
     * phase a screen would otherwise read as a dead process.
     */
    public function preflight(): self
    {
        $touch = $this->touch;

        return new self(
            $this->jobId,
            true,
            null,
            $touch === null ? null : static function () use ($touch): void {
                $touch();
            },
            null,
            $this->organisationId,
        );
    }

    /**
     * Folds a preflight's story into this run's log, and never its counts.
     *
     * Only the summaries travel: a preflight's row problems were not written
     * anywhere (a preview writes nothing), and the real pass reports the same
     * rows again as it reaches them. What the operator needs from the
     * preflight in the log is the shape of the file it found.
     */
    public function absorbPreflight(self $preflight): void
    {
        $preflight->summariseFiles();

        foreach ($preflight->result()->notes as $note) {
            $this->add(ImportNote::of($note->level, 'Preflight: ' . $note->message));
        }
    }

    /**
     * The Canvas-shaped summary, one note per file, written once.
     *
     * This is what the job log carries instead of the rows: a run whose file
     * produced ten thousand problems writes one line here and ten thousand
     * rows there, and the flush that rewrites the log whole stays cheap. A
     * run that reported no rows at all writes nothing, so an ordinary course
     * migration's log is exactly what it was.
     */
    public function summariseFiles(): void
    {
        if ($this->summarised) {
            return;
        }

        $this->summarised = true;

        foreach ($this->files as $file => $counts) {
            $this->add(ImportNote::of(
                ($counts[RowProblem::OUTCOME_FAILED] ?? 0) > 0 ? ImportNote::WARNING : ImportNote::INFO,
                sprintf(
                    '%s%d rows, %d created, %d updated, %d unchanged, %d skipped, %d failed.',
                    $file === '' ? '' : $file . ': ',
                    $counts['rows'] ?? 0,
                    $counts[RowProblem::OUTCOME_CREATED] ?? 0,
                    $counts[RowProblem::OUTCOME_UPDATED] ?? 0,
                    $counts[RowProblem::OUTCOME_UNCHANGED] ?? 0,
                    $counts[RowProblem::OUTCOME_SKIPPED] ?? 0,
                    $counts[RowProblem::OUTCOME_FAILED] ?? 0,
                ),
            ));
        }
    }

    public function result(): ImportResult
    {
        return new ImportResult($this->totals, $this->notes, $this->dryRun, $this->rejectedRows);
    }

    /** How many rows this run reported as skipped or failed. */
    public function rejectedRows(): int
    {
        return $this->rejectedRows;
    }

    /**
     * Counts one row into its file's summary and into the run's per-outcome
     * totals, which is why an importer never counts a row twice: it reports
     * the outcome once and both counters move together.
     */
    private function countRow(string $file, string $outcome, bool $reported = false): void
    {
        if (!\in_array($outcome, RowProblem::OUTCOMES, true)) {
            return;
        }

        $file = ImportNote::excerpt($file, 190);
        $this->files[$file]['rows'] = ($this->files[$file]['rows'] ?? 0) + 1;
        $this->files[$file][$outcome] = ($this->files[$file][$outcome] ?? 0) + 1;

        if ($reported) {
            // Exactly the rows the failed-rows download is built from:
            // reported through rowProblem(), and rejected on
            // RowProblem::wasRejected(). Counting them is what decides whether
            // the job finished cleanly, so the status, the download and the
            // per-file summary cannot disagree about which rows the operator
            // has to fix.
            //
            // A quiet skip reported through rowOutcome() is deliberately not
            // one. Two hundred hotspot questions the free build has no type
            // for are a fact about the source rather than rows to go back and
            // correct: they are counted, they get their summary line, and they
            // do not make a job that did everything it could report itself as
            // having problems the operator can open and act on.
            $this->rejectedRows++;
        }

        $this->counted(ImportResult::totalForOutcome($outcome));
    }

    private function add(ImportNote $note): void
    {
        $this->notes[] = $note;

        if ($this->flush === null) {
            return;
        }

        $count = \count($this->notes);

        if ($count > self::EAGER_NOTES && $count < $this->flushAt) {
            return;
        }

        if ($count > self::EAGER_NOTES) {
            $this->interval = min(self::MAX_BATCH_NOTES, $this->interval * 2);
        }

        $this->flushAt = $count + $this->interval;
        ($this->flush)($this);
    }
}
