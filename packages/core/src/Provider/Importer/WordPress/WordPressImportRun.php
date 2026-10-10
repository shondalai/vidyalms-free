<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\WordPress;

use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportProgress;
use Shondalai\VidyaLMS\Core\Registry\Importer\RecordsRowProblems;
use Shondalai\VidyaLMS\Core\Registry\Importer\UpsertPolicy;

/**
 * The state of one WordPress LMS run: who it runs as, where its notes go, what
 * the operator asked for, and the tallies that can only be reported once
 * everything has been seen.
 *
 * It is the Community Quiz run object's shape with the wave's row level error
 * model wired into it. A row that goes wrong is reported as a `RowProblem`
 * through the progress sink, which stores it in its own table and writes one
 * Canvas-shaped summary per file; a row that goes right reports its outcome
 * the same way, so the two counters cannot disagree about what happened to a
 * row. The job log therefore carries one line per file rather than one line
 * per bad row, which is the whole reason the problems are not notes: the log
 * is rewritten whole on every progress flush, and ten thousand bad rows in it
 * would make that flush quadratic.
 *
 * ## What a "file" is for a database source
 *
 * `RowProblem::file` is documented as the CSV name inside the zip. These three
 * importers read a database, so the nearest true thing is the name of the
 * place the row came from: a reader names the product's own table or post type
 * (`learndash_pro_quiz_question`, `sfwd-topic`), and the writer, which knows
 * no product vocabulary at all, names the object it was writing (`course`,
 * `lesson`, `question`). An operator reading the summary therefore sees which
 * side of the migration a row was lost on, which is the first thing they need.
 *
 * ## Why one error note, and only one
 *
 * A job finishes cleanly or it finishes with problems, and the runner decides
 * which from whether the result carries an error note. So a run that rejected
 * any row writes exactly one, at the end, saying how many. One line keeps the
 * log linear; without it, a run that lost four hundred learners would report
 * itself green.
 */
final class WordPressImportRun
{
    /** @var array<string, int> */
    private array $tallies = [];

    /** How many rows were rejected outright, which is what decides the job's terminal status. */
    private int $rejected = 0;

    /** @var array<string, int> the item source id a completion names, to the local lesson id */
    private array $lessons = [];

    public function __construct(
        public readonly Principal $principal,
        public readonly ImportProgress $progress,
        public readonly string $upsert,
        public readonly bool $quizzes,
        public readonly bool $learners,
        public readonly bool $progressRows,
    ) {
    }

    public function isDryRun(): bool
    {
        return $this->progress->isDryRun();
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
     * Something no mapping anticipated went wrong and cost a whole source
     * object. It is an error rather than a warning because nobody chose it.
     *
     * It counts nothing. It used to count a TOTAL_SKIPPED, which made the
     * closing summary sentence count itself as a skipped row: a run whose per
     * file summaries named two skipped rows reported a job total of three, and
     * the recorder had already counted both of those rows through
     * countRow(). "An importer never counts a row twice" is the recorder's own
     * rule and this was the fourth place it was broken.
     */
    public function failed(string $message): void
    {
        $this->progress->note(ImportNote::ERROR, $message);
    }

    public function counted(string $what, int $delta = 1): void
    {
        $this->progress->counted($what, $delta);
    }

    /**
     * One row the importer deliberately did not bring in, explained in the log
     * and counted in the totals. Used where the explanation is worth a line of
     * its own; a row whose explanation is the same as every other row of its
     * kind is reported through problem() and tallied instead.
     */
    public function skipped(string $message): void
    {
        $this->warn($message);
    }

    /**
     * One row not brought in whose explanation is deferred to the closing
     * summary, because it is the same explanation for every row of its kind.
     */
    public function skippedQuietly(string $tally, int $delta = 1): void
    {
        $this->tally($tally, $delta);
    }

    /**
     * Records one row level problem: which file, which row, which column, a
     * stable code, a sentence, a bounded excerpt of the offending value and
     * what happened to the row.
     *
     * The row number is one based and includes the header, matching what an
     * operator's spreadsheet shows them. A database source has no header, so
     * `row` is the source object's own identifier and `file` names where the
     * row came from.
     */
    public function problem(
        string $file,
        int $row,
        ?string $column,
        string $code,
        string $message,
        string $value = '',
        string $outcome = RowProblem::OUTCOME_SKIPPED,
    ): void {
        $this->countRejected($outcome);

        $this->record(RowProblem::of($file, max(1, $row), $column, $code, $message, $value, $outcome));
    }

    /**
     * The same, from whatever a use case threw, so a ValidationException's
     * field errors expand in the row report the way they expand in the log.
     */
    public function problemFrom(
        string $file,
        int $row,
        ?string $column,
        string $code,
        \Throwable $failure,
        string $value = '',
        string $outcome = RowProblem::OUTCOME_FAILED,
    ): void {
        $this->countRejected($outcome);

        $this->record(RowProblem::fromFailure($file, max(1, $row), $column, $code, $failure, $value, $outcome));
    }

    /**
     * One row that went fine, counted into its file's summary and into the
     * run's per-outcome totals.
     */
    public function rowOutcome(string $file, string $outcome): void
    {
        if ($this->progress instanceof RecordsRowProblems) {
            $this->progress->rowOutcome($file, $outcome);
        }
    }

    /**
     * How many rows this run rejected, which is what the closing sentence
     * counts.
     */
    public function rejected(): int
    {
        return $this->rejected;
    }

    /**
     * One predicate for "the operator has to fix this row and re-upload it",
     * and it is RowProblem's own.
     *
     * This counted OUTCOME_FAILED alone, while the Joomla family's SourceRun
     * counted every problem, so one identical source shape ended
     * completed_with_problems on one host family and completed on the other:
     * a LearnDash migration whose quizzes were all matrix questions produced
     * four skipped rows, a failed-rows download full of work, and a green job.
     * RowProblem::wasRejected() is what the failed-rows download itself is
     * built from, so using it here is what makes the status, the download and
     * the per-file summary agree.
     */
    private function countRejected(string $outcome): void
    {
        if (RowProblem::isRejection($outcome)) {
            $this->rejected++;
        }
    }

    public function mayCreate(): bool
    {
        return $this->upsert !== UpsertPolicy::UPDATE_ONLY;
    }

    public function mayUpdate(): bool
    {
        return $this->upsert === UpsertPolicy::CREATE_AND_UPDATE || $this->upsert === UpsertPolicy::UPDATE_ONLY;
    }

    public function duplicates(): bool
    {
        return $this->upsert === UpsertPolicy::CREATE_WITH_SUFFIX;
    }

    public function tally(string $what, int $delta = 1): void
    {
        $this->tallies[$what] = ($this->tallies[$what] ?? 0) + $delta;
    }

    /**
     * Every tally whose name starts with the prefix, keyed by the remainder:
     * tallies("question:") answers "which question types were left behind, and
     * how many of each".
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

    public function rememberLesson(string $sourceId, int $lessonId): void
    {
        if ($lessonId > 0) {
            $this->lessons[$sourceId] = $lessonId;
        }
    }

    public function lessonFor(string $sourceId): ?int
    {
        return $this->lessons[$sourceId] ?? null;
    }

    /**
     * A sink that cannot take rows still gets an honest total, because the
     * skip count is the one number every Import screen has always shown.
     */
    private function record(RowProblem $problem): void
    {
        if ($this->progress instanceof RecordsRowProblems) {
            $this->progress->rowProblem($problem);

            return;
        }

        $this->counted(ImportResult::totalForOutcome($problem->outcome));
    }
}
