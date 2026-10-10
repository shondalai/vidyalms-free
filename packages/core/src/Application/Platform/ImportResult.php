<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

/**
 * What one import run produced. Totals count things, notes explain them, and
 * dryRun says whether any of it was actually written.
 *
 * Totals are a free map so an importer can count what its source has, but the
 * keys below are the shared vocabulary: an admin screen showing "courses" and
 * "lessons" must not have to know which importer ran. Use TOTAL_SKIPPED for
 * everything the importer deliberately did not bring in.
 *
 * Serialised, this is the shape stored on import_jobs.totals and returned by
 * ImporterInterface::import():
 *
 *   {
 *     "totals":  {"courses": 1, "modules": 3, "lessons": 12, "skipped": 2},
 *     "notes":   [{"level": "info", "message": "..."}],
 *     "dry_run": false
 *   }
 *
 * A note is one line and its level is data (see ImportNote), so log() and
 * ImportNote::parseLog() are exact inverses: a job log read back carries the
 * levels it was written with, and no source text inside a message can add a
 * line or a level of its own.
 */
final class ImportResult
{
    public const TOTAL_COURSES = 'courses';
    public const TOTAL_MODULES = 'modules';
    public const TOTAL_LESSONS = 'lessons';
    public const TOTAL_QUIZZES = 'quizzes';
    public const TOTAL_QUESTIONS = 'questions';
    public const TOTAL_ENROLMENTS = 'enrolments';
    public const TOTAL_LEARNERS = 'learners';
    public const TOTAL_SKIPPED = 'skipped';

    /**
     * The four organisation totals a roster load counts.
     *
     * TOTAL_ENROLMENTS and TOTAL_LEARNERS are deliberately reused rather than
     * duplicated with organisation-flavoured names: an enrolment written by a
     * OneRoster file is the same row an enrolment written by a Moodle backup
     * is, and an admin screen showing "enrolments" must not have to know which
     * importer ran. What is genuinely new is the four below, because nothing
     * before this counted an organisation, a membership, an invitation or a
     * seat.
     */
    public const TOTAL_ORGS = 'orgs';
    public const TOTAL_ORG_MEMBERS = 'org_members';
    public const TOTAL_INVITATIONS = 'invitations';
    public const TOTAL_SEATS = 'seats';

    /**
     * The per-outcome counters, beside the skip count that was there alone.
     *
     * TOTAL_SKIPPED answered "how much did this run not bring in", which is
     * one of the five questions an operator asks after a forty thousand row
     * file and the least useful of them. A run reporting 39,600 created and
     * 400 failed is actionable; the same run reporting 400 skipped is not,
     * because a skip that was deliberate and a skip that was a rejection read
     * identically. The five keys are RowProblem's own outcome vocabulary, so
     * a row counted here and a row reported there cannot disagree about what
     * happened to it.
     */
    public const TOTAL_CREATED = 'created';
    public const TOTAL_UPDATED = 'updated';
    public const TOTAL_UNCHANGED = 'unchanged';
    public const TOTAL_FAILED = 'failed';

    /**
     * The outcome vocabulary as totals keys, in RowProblem's own order.
     *
     * @var array<string, string>
     */
    public const OUTCOME_TOTALS = [
        RowProblem::OUTCOME_CREATED => self::TOTAL_CREATED,
        RowProblem::OUTCOME_UPDATED => self::TOTAL_UPDATED,
        RowProblem::OUTCOME_UNCHANGED => self::TOTAL_UNCHANGED,
        RowProblem::OUTCOME_SKIPPED => self::TOTAL_SKIPPED,
        RowProblem::OUTCOME_FAILED => self::TOTAL_FAILED,
    ];


    /**
     * @param array<string, int> $totals
     * @param list<ImportNote>   $notes
     * @param int                $rejectedRows how many rows the run reported as skipped or failed
     */
    public function __construct(
        public readonly array $totals = [],
        public readonly array $notes = [],
        public readonly bool $dryRun = false,
        public readonly int $rejectedRows = 0,
    ) {
    }

    /**
     * Reads back the serialised shape. Tolerant on purpose: an importer that
     * returns only a totals map, or the older {"totals": ..., "log": [...]}
     * shape, still produces a usable result.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $totals = self::intMap($payload['totals'] ?? null);
        $notes = self::notesFrom($payload['notes'] ?? null);

        if ($notes === []) {
            $notes = self::notesFrom($payload['log'] ?? null);
        }

        if ($totals === [] && !isset($payload['totals'])) {
            unset($payload['notes'], $payload['log'], $payload['dry_run']);
            $totals = self::intMap($payload);
        }

        return new self($totals, $notes, (bool) ($payload['dry_run'] ?? false));
    }

    /**
     * @return array{totals: array<string, int>, notes: list<array{level: string, message: string}>, dry_run: bool}
     */
    public function toArray(): array
    {
        return [
            'totals' => $this->totals,
            'notes' => array_map(static fn (ImportNote $note): array => $note->toArray(), $this->notes),
            'dry_run' => $this->dryRun,
        ];
    }

    /**
     * The notes as job log text, one line each.
     */
    public function log(): string
    {
        return implode("\n", array_map(static fn (ImportNote $note): string => $note->line(), $this->notes));
    }

    /**
     * The totals key one row outcome counts into.
     *
     * It exists so a mapper counts an outcome by naming the outcome, and
     * cannot count a "skipped" row into "failed" by typing the wrong string.
     */
    public static function totalForOutcome(string $outcome): string
    {
        return self::OUTCOME_TOTALS[$outcome] ?? self::TOTAL_SKIPPED;
    }

    public function total(string $what): int
    {
        return $this->totals[$what] ?? 0;
    }

    public function hasErrors(): bool
    {
        foreach ($this->notes as $note) {
            if ($note->level === ImportNote::ERROR) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this run finished cleanly, which is not the same question as
     * whether it finished.
     *
     * Two predicates, and the first one is the one that was missing. A run
     * reporting rows through the published row model alone, with no error
     * note at all, used to end "completed": twenty thousand rejected rows, a
     * failed-rows download full of work, a per-file summary saying so, and a
     * green job. The error note was a thing each importer had to remember to
     * write, and the two shipped families disagreed about when to write it,
     * so one identical source shape ended green on one host family and with
     * problems on the other.
     *
     * The recorder already counted every rejected row, once, through the
     * outcome the importer reported, so the count is the predicate. The note
     * stays in the second half because a run can fail in a way that costs no
     * single row: a source that would not open, a stage that threw. When it is
     * the only half that answered, the job log is where the story is and the
     * runner says so in a note, because a row report with nothing in it reads
     * as "this import went perfectly".
     */
    public function finishedWithProblems(): bool
    {
        return $this->rejectedRows > 0 || $this->hasErrors();
    }

    /**
     * @return array<string, int>
     */
    private static function intMap(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $totals = [];

        /** @var mixed $count */
        foreach ($value as $key => $count) {
            if (!\is_string($key) || !is_numeric($count)) {
                continue;
            }

            $totals[$key] = (int) $count;
        }

        return $totals;
    }

    /**
     * @return list<ImportNote>
     */
    private static function notesFrom(mixed $value): array
    {
        if (\is_string($value)) {
            $value = $value === '' ? [] : explode("\n", $value);
        }

        if (!\is_array($value)) {
            return [];
        }

        $notes = [];

        /** @var mixed $entry */
        foreach ($value as $entry) {
            if (\is_string($entry)) {
                // A log line carries its level in front of it, and reading it
                // back through the writer's own parser is what keeps a
                // warning a warning across a round trip.
                $notes[] = ImportNote::fromLine($entry);
                continue;
            }

            if (!\is_array($entry)) {
                continue;
            }

            $message = $entry['message'] ?? null;

            if (!\is_string($message)) {
                continue;
            }

            $level = $entry['level'] ?? ImportNote::INFO;
            $notes[] = ImportNote::of(\is_string($level) ? $level : ImportNote::INFO, $message);
        }

        return $notes;
    }
}
