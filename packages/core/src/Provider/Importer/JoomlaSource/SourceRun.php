<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource;

use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportProgress;
use Shondalai\VidyaLMS\Core\Registry\Importer\RecordsRowProblems;
use Shondalai\VidyaLMS\Core\Registry\Importer\UpsertPolicy;

/**
 * The state of one run of one Joomla source importer: who it runs as, where
 * its rows go, what it was asked to do, and the tallies it can only report
 * once it has seen everything.
 *
 * ## Every row goes through one door
 *
 * problem() and rowOutcome() are the only two ways a row is reported, and
 * both hand it to the progress sink's row report rather than writing a line
 * in the job log. That is the row level error model's own reason: the log is
 * rewritten whole on every progress flush, so a course with ten thousand
 * unmappable questions would make the flush quadratic, while a row in its own
 * table is one append. The log gets the per-file summary the recorder writes
 * from these counts, and nothing else.
 *
 * A sink that does not take rows still loses nothing: the fallback writes the
 * problem as a warning and counts it, which is what these importers did
 * before the row report existed.
 *
 * ## What "file" and "row" mean for a database source
 *
 * The row report was designed for a CSV, where the file is a name inside a
 * zip and the row is a line number an operator can find in their
 * spreadsheet. A database import has neither, so the source table stands in
 * for the file and the source row's own primary key stands in for the line
 * number, because that pair is exactly what an operator types into their own
 * admin to find the row. The key is where the number comes from: these
 * importers mint keys like "lesson:41" and "question:7" from the product's
 * own ids, so the last run of digits in the key is that id.
 */
final class SourceRun
{
    /** @var array<string, int> */
    private array $tallies = [];

    /** @var array<string, int> foreign course key to the local course id */
    private array $courses = [];

    private int $problems = 0;

    public function __construct(
        public readonly Principal $principal,
        public readonly ImportProgress $progress,
        public readonly string $upsertPolicy,
        public readonly bool $learners,
        public readonly bool $completions,
        public readonly bool $publish,
        public readonly bool $quizzes,
    ) {
    }

    public function isDryRun(): bool
    {
        return $this->progress->isDryRun();
    }

    public function jobId(): int
    {
        return $this->progress->jobId();
    }

    public function note(string $message): void
    {
        $this->progress->note(ImportNote::INFO, $message);
    }

    public function warn(string $message): void
    {
        $this->progress->note(ImportNote::WARNING, $message);
    }

    /**
     * An error level note, and no row.
     *
     * It exists for exactly one sentence, the one the run closes with when it
     * rejected rows, and that sentence has a mechanical job as well as a
     * readable one: ImportRunner ends a job as completed_with_problems rather
     * than completed when ImportResult::hasErrors() is true, and hasErrors()
     * reads the notes. Without this a run that left four hundred questions
     * behind would end green, which is the exact failure the terminal state
     * was added to prevent.
     */
    public function error(string $message): void
    {
        $this->progress->note(ImportNote::ERROR, $message);
    }

    /**
     * Something no mapping anticipated cost a whole source object. It is an
     * error rather than a warning because nobody chose it, and it is one
     * failed row because one thing was lost.
     */
    public function failed(string $table, string $message): void
    {
        $this->progress->note(ImportNote::ERROR, $message);
        $this->rowOutcome($table, RowProblem::OUTCOME_FAILED);
        $this->problems++;
    }

    public function counted(string $what, int $delta = 1): void
    {
        $this->progress->counted($what, $delta);
    }

    /** Whether an object this run has not seen before may be created. */
    public function createsNew(): bool
    {
        return $this->upsertPolicy !== UpsertPolicy::UPDATE_ONLY;
    }

    /** Whether an object a previous run created is written to again. */
    public function updatesExisting(): bool
    {
        return $this->upsertPolicy === UpsertPolicy::CREATE_AND_UPDATE
            || $this->upsertPolicy === UpsertPolicy::UPDATE_ONLY;
    }

    /**
     * Whether the import map is consulted at all. create_with_suffix is the
     * one policy that does not look, because looking is what would stop it
     * making the second copy it exists to make, and it does not re-point the
     * first run's mapping either.
     */
    public function readsTheMap(): bool
    {
        return $this->upsertPolicy !== UpsertPolicy::CREATE_WITH_SUFFIX;
    }

    /**
     * One row this importer would not or could not bring in, with the stable
     * code that says why.
     *
     * @param string      $code      one of RowProblem::CODES
     * @param string      $table     the source table, standing in for the file
     * @param string      $sourceKey the key this importer minted for the row
     * @param string|null $column    the column at fault, or null for the whole row
     * @param string      $value     the offending source value, bounded and neutralised by RowProblem
     */
    public function problem(
        string $code,
        string $table,
        string $sourceKey,
        ?string $column,
        string $value,
        string $message,
        string $outcome = RowProblem::OUTCOME_FAILED,
    ): void {
        // RowProblem's own predicate, which is also what the failed-rows
        // download is built from. It counted every problem, including a
        // warning on a row that was written, while the WordPress family
        // counted only OUTCOME_FAILED: two error models on one registry, and
        // an identical source shape finishing green on one host family and
        // with problems on the other.
        if (RowProblem::isRejection($outcome)) {
            $this->problems++;
        }

        $problem = RowProblem::of($table, self::rowOf($sourceKey), $column, $code, $message, $value, $outcome);

        if ($this->progress instanceof RecordsRowProblems) {
            $this->progress->rowProblem($problem);

            return;
        }

        // A sink with no row report loses nothing: the problem reads as the
        // warning it would have been before the report existed, and it is
        // counted the same way.
        $this->warn($problem->line());
        $this->counted(ImportResult::totalForOutcome($outcome));
    }

    /**
     * One row that went fine, counted into its table's summary and into the
     * run's per-outcome totals.
     */
    public function rowOutcome(string $table, string $outcome, int $delta = 1): void
    {
        for ($written = 0; $written < $delta; $written++) {
            if ($this->progress instanceof RecordsRowProblems) {
                $this->progress->rowOutcome($table, $outcome);
                continue;
            }

            $this->counted(ImportResult::totalForOutcome($outcome));
        }
    }

    /**
     * One row deliberately not brought in whose explanation is deferred to
     * the closing summary, because it is the same explanation for every row
     * of its kind. A course with two hundred hotspot questions writes one
     * summary line rather than two hundred.
     */
    public function skippedQuietly(string $tally, string $table, int $delta = 1): void
    {
        $this->tally($tally, $delta);
        $this->rowOutcome($table, RowProblem::OUTCOME_SKIPPED, $delta);
    }

    /** How many rows this run rejected, on RowProblem's own predicate. */
    public function problemCount(): int
    {
        return $this->problems;
    }

    public function tally(string $what, int $delta = 1): void
    {
        $this->tallies[$what] = ($this->tallies[$what] ?? 0) + $delta;
    }

    public function tallied(string $what): int
    {
        return $this->tallies[$what] ?? 0;
    }

    /**
     * Every tally whose name starts with the prefix, keyed by the remainder:
     * tallies("question:") answers "which question types were left behind,
     * and how many of each".
     *
     * @return array<string, int>
     */
    public function tallies(string $prefix): array
    {
        $matched = [];

        foreach ($this->tallies as $name => $count) {
            if (str_starts_with($name, $prefix)) {
                $matched[substr($name, \strlen($prefix))] = $count;
            }
        }

        ksort($matched);

        return $matched;
    }

    public function rememberCourse(string $sourceKey, int $localId): void
    {
        if ($localId > 0) {
            $this->courses[$sourceKey] = $localId;
        }
    }

    /**
     * @return array<string, int>
     */
    public function importedCourses(): array
    {
        return $this->courses;
    }

    /**
     * The source row's own id, out of the key this importer minted for it.
     *
     * One derivation, in the class that owns the row concept: the WordPress
     * family took the first run of digits and this took the last, so one
     * composed key reported two different rows depending on which host family
     * read it.
     */
    private static function rowOf(string $sourceKey): int
    {
        return RowProblem::rowFromKey($sourceKey);
    }
}
