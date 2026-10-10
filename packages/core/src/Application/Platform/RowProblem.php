<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Domain\Reporting\CellSafetyPolicy;

/**
 * One row of an import that did not go the way the file said it would.
 *
 * Before this existed an importer had exactly three ways to complain, and
 * none of them named a row: ImportNote carries a level and a sentence,
 * ImportResult carries a flat integer totals map, and ImportProblem renders a
 * Throwable into one line. So a forty thousand row roster whose four hundred
 * bad rows are scattered through it produced four hundred sentences an
 * operator had to read one at a time, with no row number to take back to
 * their spreadsheet, no column to fix, no stable token to filter on and no
 * way to page. This is the shape that answers all four.
 *
 * ## Row numbers are one based and include the header
 *
 * Row 1 is the header line, so the first data row is row 2. That is what
 * Canvas reports and it is what a spreadsheet's own line numbers say, and an
 * operator matching a report against their file is the whole reason the
 * number is carried. An importer counting data rows from zero and reporting
 * that number sends somebody to the wrong line, which is worse than no
 * number at all.
 *
 * ## The code is a closed vocabulary
 *
 * Eight tokens, and they are the vocabulary published in
 * docs/technical/organisations/spec.json. They exist so a screen can group
 * and filter without reading English, so a support engineer can be told "the
 * failures are all unmapped_role" in one word, and so a fix for one class of
 * problem is a query rather than a grep. A ninth token is a change to the
 * published vocabulary and to the spec, never a string an importer invents:
 * of() refuses anything else, because a vocabulary that admits one unknown
 * value is not one.
 *
 * ## message goes through ImportProblem
 *
 * Deliberately, and this is why ImportProblem is not being replaced. A
 * ValidationException carries the reason in its errors map while
 * getMessage() is the generic "Validation failed.", so a row report built
 * from getMessage() would tell an operator that a row was rejected and
 * nothing about what to fix. fromFailure() runs the throwable through
 * ImportProblem::describe(), so field errors expand into the row report
 * exactly as they already expand into the job log.
 *
 * ## value is a source excerpt and it is neutralised here
 *
 * It is a verbatim fragment of somebody's CSV, which means it may hold a
 * name, an email address or a formula. Three consequences, all of them
 * enforced on this object rather than remembered by each importer:
 *
 * - it is bounded by ImportNote::EXCERPT_LIMIT, the same 120 characters a
 *   note quotes a source value through, because the row problem table is
 *   read whole by a paged screen;
 * - it is flattened to one line by the same rule ImportNote uses, so a cell
 *   holding a line break cannot forge a second entry;
 * - it is passed through CellSafetyPolicy in its spreadsheet-safe mode, once,
 *   here. The design names import_row_problems.value as one of the three
 *   places the policy applies. The failed-rows download applies the policy to
 *   the raw source cells it re-emits and never to this field again, because
 *   apply() prefixes rather than escapes and applying it twice would write
 *   two tabs and change the value the operator is trying to fix.
 *
 * ## outcome says what happened to the row, not how bad it was
 *
 * created, updated and unchanged are outcomes a row problem may legitimately
 * carry: a warning on a row that was still written is a real thing an
 * importer reports, and collapsing it into "failed" would lie about the
 * database. skipped means the row was understood and deliberately not
 * written; failed means it was not understood.
 *
 * Row problems live in their own table rather than in the job log, and the
 * reason is mechanical rather than aesthetic: the log is rewritten whole on
 * every progress flush, so ten thousand bad rows in a forty thousand row file
 * make the flush quadratic. The log gets one summary note per file instead.
 */
final class RowProblem
{
    /** The row named an organisation that does not exist or is not in scope. */
    public const CODE_UNKNOWN_ORG = 'unknown_org';

    /** A foreign role token no registered mapper translates. */
    public const CODE_UNMAPPED_ROLE = 'unmapped_role';

    /** The same key appears twice inside one file. */
    public const CODE_DUPLICATE_KEY = 'duplicate_key';

    /** A column the format makes mandatory is empty or absent. */
    public const CODE_MISSING_REQUIRED = 'missing_required';

    /** An address that is not one. */
    public const CODE_INVALID_EMAIL = 'invalid_email';

    /** There was no seat left in the pool the run was told to draw from. */
    public const CODE_SEAT_POOL_EXHAUSTED = 'seat_pool_exhausted';

    /** The row names a parent, a unit or a session no file in the set defines. */
    public const CODE_REFERENTIAL_MISS = 'referential_miss';

    /** The row asks for something the caller's scope does not cover. */
    public const CODE_FORBIDDEN_SCOPE = 'forbidden_scope';

    /**
     * The whole vocabulary, in the order the spec publishes it.
     *
     * @var list<string>
     */
    public const CODES = [
        self::CODE_UNKNOWN_ORG,
        self::CODE_UNMAPPED_ROLE,
        self::CODE_DUPLICATE_KEY,
        self::CODE_MISSING_REQUIRED,
        self::CODE_INVALID_EMAIL,
        self::CODE_SEAT_POOL_EXHAUSTED,
        self::CODE_REFERENTIAL_MISS,
        self::CODE_FORBIDDEN_SCOPE,
    ];

    public const OUTCOME_CREATED = 'created';
    public const OUTCOME_UPDATED = 'updated';
    public const OUTCOME_UNCHANGED = 'unchanged';
    public const OUTCOME_SKIPPED = 'skipped';
    public const OUTCOME_FAILED = 'failed';

    /**
     * @var list<string>
     */
    public const OUTCOMES = [
        self::OUTCOME_CREATED,
        self::OUTCOME_UPDATED,
        self::OUTCOME_UNCHANGED,
        self::OUTCOME_SKIPPED,
        self::OUTCOME_FAILED,
    ];

    /** The header line is row 1, so no data row is ever below this. */
    public const FIRST_DATA_ROW = 2;

    /** The stored width of import_row_problems.file in schema/tables.php. */
    public const FILE_LIMIT = 190;

    /**
     * The stored width of import_row_problems.column_name in
     * schema/tables.php.
     *
     * The two disagreed until the R4 review: this said 190 and the column said
     * 120, so a 190-character header was written at 190 bytes into a column
     * that holds 120. SQLite ignores a VARCHAR length, so the whole suite
     * passed on a width MySQL in strict mode refuses with error 1406, and 1406
     * on the row-problem insert is the pipeline's worst moment to lose a job.
     * The column was widened rather than this being narrowed, because a
     * hundred and ninety character column name is a real thing in an export
     * and truncating it takes away the one string the operator searches their
     * spreadsheet for.
     */
    public const COLUMN_LIMIT = 190;

    /** The stored width of import_row_problems.message in schema/tables.php. */
    public const MESSAGE_LIMIT = 1000;

    /**
     * The stored width of import_row_problems.value_excerpt in
     * schema/tables.php.
     *
     * The excerpt is bounded to this **after** CellSafetyPolicy has run, not
     * before. The policy prefixes a tab rather than escaping, so a dangerous
     * cell bounded to 120 first and neutralised second came out at 121, which
     * is one byte wider than the column, for every formula-shaped value of a
     * hundred and twenty characters or more. Bounding afterwards costs a
     * dangerous value one character of its excerpt and costs an ordinary one
     * nothing at all.
     */
    public const VALUE_LIMIT = ImportNote::EXCERPT_LIMIT;

    private function __construct(
        public readonly string $file,
        public readonly int $row,
        public readonly ?string $column,
        public readonly string $code,
        public readonly string $message,
        public readonly string $value,
        public readonly string $outcome,
    ) {
    }

    /**
     * One row problem.
     *
     * @param string  $file    the CSV name inside the zip, or '' for a single file
     * @param int     $row     1 based and including the header, so data starts at 2
     * @param ?string $column  the header name, or null when the whole row is the problem
     * @param string  $code    one of the CODES
     * @param string  $message already composed; run a throwable through fromFailure() instead
     * @param string  $value   a source excerpt, bounded and neutralised here
     * @param string  $outcome one of the OUTCOMES
     *
     * @throws \InvalidArgumentException on a code or an outcome nobody publishes
     */
    public static function of(
        string $file,
        int $row,
        ?string $column,
        string $code,
        string $message,
        string $value = '',
        string $outcome = self::OUTCOME_FAILED,
    ): self {
        if (!\in_array($code, self::CODES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'A row problem code is one of %s; "%s" is none of them.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', self::CODES),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $code,
            ));
        }

        if (!\in_array($outcome, self::OUTCOMES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'A row outcome is one of %s; "%s" is none of them.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', self::OUTCOMES),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $outcome,
            ));
        }

        $column = $column === null ? null : ImportNote::excerpt($column, self::COLUMN_LIMIT);

        return new self(
            ImportNote::excerpt($file, self::FILE_LIMIT),
            max(1, $row),
            $column === '' ? null : $column,
            $code,
            ImportNote::excerpt($message, self::MESSAGE_LIMIT),
            self::safeExcerpt($value),
            $outcome,
        );
    }

    /**
     * One row problem in a real file, where the one-based-including-the-header
     * rule is a rule rather than a convention.
     *
     * of() is deliberately tolerant, because a database source has no header
     * and reports the source object's own identifier as its row. A file source
     * has both, and the rule the class documents ("row 1 is the header line, so
     * the first data row is row 2") was stated and never enforced: of() folded
     * row 0 up to 1, which is the header line, so an importer counting its data
     * rows from zero sent the operator to the wrong line for every row in the
     * file and nothing said so.
     *
     * @throws \InvalidArgumentException on a row a data line cannot be
     */
    public static function inFile(
        string $file,
        int $row,
        ?string $column,
        string $code,
        string $message,
        string $value = '',
        string $outcome = self::OUTCOME_FAILED,
    ): self {
        if ($row < self::FIRST_DATA_ROW) {
            throw new \InvalidArgumentException(sprintf(
                'Row numbers in a file are one based and include the header, so the first data row is %d;'
                    . ' %d is the header line or before it. An importer counting data rows from zero sends the'
                    . ' operator to the wrong line for every row it reports.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::FIRST_DATA_ROW,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $row,
            ));
        }

        return self::of($file, $row, $column, $code, $message, $value, $outcome);
    }

    /**
     * The row a problem names, out of the key an importer minted for the
     * object.
     *
     * The last run of digits, so "lesson:41" is 41 and "course:12/40" is 40:
     * a composed key ends with the object's own identity and opens with its
     * parent's. The two run objects derived it two opposite ways, so one
     * source key reported 12 on the Joomla side and 40 on the WordPress side,
     * and R5 was about to add a third. A key with no digits at all answers the
     * header row, which reads as "this file, no particular line".
     */
    public static function rowFromKey(string $sourceKey): int
    {
        return preg_match('/(\d+)(?!.*\d)/', $sourceKey, $matches) === 1 ? (int) $matches[1] : 1;
    }

    /**
     * The same thing, from whatever the use case threw.
     *
     * This is the reason ImportProblem stays exactly what it is: a
     * ValidationException's field errors expand into the row report here the
     * same way they expand into the job log there, so one rejected row reads
     * as "email: An address is required." in both places rather than as
     * "Validation failed." in the one an operator actually opens.
     */
    public static function fromFailure(
        string $file,
        int $row,
        ?string $column,
        string $code,
        \Throwable $failure,
        string $value = '',
        string $outcome = self::OUTCOME_FAILED,
    ): self {
        return self::of($file, $row, $column, $code, ImportProblem::describe($failure), $value, $outcome);
    }

    /**
     * Reads back the stored shape. Tolerant in the same way ImportResult is,
     * because a row read from a table written by an older build must still
     * render: an unknown code or outcome is not a reason to fail a page of
     * problems, so both fall back rather than throw.
     *
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $code = $payload['code'] ?? null;
        $outcome = $payload['outcome'] ?? null;
        $column = $payload['column'] ?? null;

        return self::of(
            \is_string($payload['file'] ?? null) ? $payload['file'] : '',
            is_numeric($payload['row'] ?? null) ? (int) $payload['row'] : 1,
            \is_string($column) && $column !== '' ? $column : null,
            \is_string($code) && \in_array($code, self::CODES, true) ? $code : self::CODE_MISSING_REQUIRED,
            \is_string($payload['message'] ?? null) ? $payload['message'] : '',
            \is_string($payload['value'] ?? null) ? $payload['value'] : '',
            \is_string($outcome) && \in_array($outcome, self::OUTCOMES, true) ? $outcome : self::OUTCOME_FAILED,
        );
    }

    /**
     * @return array{
     *     file: string,
     *     row: int,
     *     column: string|null,
     *     code: string,
     *     message: string,
     *     value: string,
     *     outcome: string
     * }
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'row' => $this->row,
            'column' => $this->column,
            'code' => $this->code,
            'message' => $this->message,
            'value' => $this->value,
            'outcome' => $this->outcome,
        ];
    }

    /**
     * Whether this problem means the row is not in the database.
     *
     * The failed-rows download is built from exactly these, because a row
     * that was written and merely warned about is not a row the operator has
     * to fix and re-upload.
     */
    public function wasRejected(): bool
    {
        return self::isRejection($this->outcome);
    }

    /**
     * The same question asked of an outcome word rather than of a problem.
     *
     * It is public because the two run objects have to ask it before they have
     * built a problem, and because they used to answer it two different ways:
     * one counted OUTCOME_FAILED alone and the other counted every problem, so
     * one source shape finished green on one host family and with problems on
     * the other. One predicate, in the class that owns the vocabulary.
     */
    public static function isRejection(string $outcome): bool
    {
        return $outcome === self::OUTCOME_SKIPPED || $outcome === self::OUTCOME_FAILED;
    }

    /**
     * One line an operator can read, for a job log summary or a plain text
     * report. The file is named only when there is one, because a single file
     * import has nothing to disambiguate.
     */
    public function line(): string
    {
        $where = $this->file === '' ? sprintf('row %d', $this->row) : sprintf('%s row %d', $this->file, $this->row);

        if ($this->column !== null) {
            $where .= sprintf(', column %s', $this->column);
        }

        return sprintf('%s [%s] %s', $where, $this->code, $this->message);
    }

    /**
     * A source value as this table stores it: one line, bounded, and safe to
     * open in a spreadsheet. Applied once, here, and never again downstream.
     */
    private static function safeExcerpt(string $value): string
    {
        $policy = CellSafetyPolicy::spreadsheetSafe();
        $safe = $policy->apply(ImportNote::excerpt($value, self::VALUE_LIMIT));

        if (\strlen($safe) <= self::VALUE_LIMIT) {
            return $safe;
        }

        // The policy prefixed a tab onto a value that already filled the
        // column, which is every dangerous cell of a hundred and twenty
        // characters or more. Make room for the prefix in the excerpt rather
        // than trimming the result: the prefix is what makes the value safe to
        // open, and cutting it back off would be the one edit that undoes the
        // whole point. Re-bounding cannot make the value ordinary, because the
        // policy reads the first character and a cut from the start keeps it.
        return $policy->apply(
            ImportNote::excerpt($value, self::VALUE_LIMIT - \strlen(CellSafetyPolicy::PREFIX)),
        );
    }
}
