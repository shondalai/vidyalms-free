<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Domain\Reporting\CellSafetyPolicy;

/**
 * The failed-rows download, written as CSV.
 *
 * It is the single most useful artefact of a big import: the operator fixes
 * the rejected rows, re-uploads only those, and idempotency makes the
 * re-upload safe. Without it, a forty thousand row load with four hundred bad
 * rows means finding four hundred lines in a spreadsheet by hand, which is
 * what makes people give up and load the whole file again.
 *
 * ## Every cell goes through CellSafetyPolicy
 *
 * A field beginning with `=`, `+`, `-`, `@`, tab, carriage return or line
 * feed, **or the full-width variants of the first four**, is executed as a
 * formula by spreadsheet software, and quoting alone does not stop it: an
 * attacker can close the quote and start a new cell, and Excel strips quotes
 * when a file is saved and reopened. This file echoes customer-supplied
 * strings straight back into a spreadsheet, which is exactly the shape the
 * policy exists for, so every cell is written through it in spreadsheet-safe
 * mode.
 *
 * The `value` column is the one exception and it is not an exception to the
 * rule, only to where the rule was applied: RowProblem neutralises its excerpt
 * when it is built, once, so applying the policy again here would prefix a
 * second tab and change the value the operator is trying to read. Applying it
 * twice is not safer than applying it once; it is a different value.
 *
 * ## RFC 4180, and why not fputcsv
 *
 * The quoting is written here rather than delegated because fputcsv needs a
 * stream and this file is returned as a string body, and because the escape
 * character argument of PHP's own implementation has changed behaviour across
 * versions in ways that produce a file Excel reads differently. The rule is
 * small: a field holding a comma, a double quote, a CR or an LF is wrapped in
 * double quotes, and an embedded double quote is doubled. Line endings are
 * CRLF, which is what RFC 4180 specifies and what Excel expects.
 *
 * A UTF-8 BOM leads the file. Excel on Windows reads a BOM-less UTF-8 CSV in
 * the system code page and turns every non-ASCII name in the file into
 * mojibake, and a report of rejected rows whose point is the names in it is
 * worth nothing when the names are unreadable.
 */
final class FailedRowsFile
{
    /** The columns, in the order an operator reads them. */
    public const HEADER = ['file', 'row', 'column', 'code', 'outcome', 'message', 'value'];

    /**
     * The code the trailing marker row carries when the download hit its cap.
     *
     * A cap with no marker is the worst of the three possible behaviours: the
     * operator fixes ten thousand rows, re-uploads, and the two thousand rows
     * that were never in the file are lost silently, because a file that ends
     * at row ten thousand and one looks exactly like a file that ended. So the
     * last line says so, in the report's own columns, and the response says so
     * again in a header for a client that never opens the body.
     */
    public const TRUNCATED_CODE = 'truncated';

    /** The header a response carries when the body is capped. */
    public const TRUNCATED_HEADER = 'X-VidyaLMS-Rejected-Rows';

    /** The header naming the cap the body was built under. */
    public const LIMIT_HEADER = 'X-VidyaLMS-Rejected-Rows-Limit';

    public const BOM = "\u{FEFF}";

    private const EOL = "\r\n";

    private function __construct()
    {
    }

    /**
     * The download's file name, which names the job so two downloads on one
     * desktop do not overwrite each other.
     */
    public static function nameFor(int $jobId): string
    {
        return sprintf('import-%d-failed-rows.csv', max(0, $jobId));
    }

    /**
     * The whole file.
     *
     * @param list<array<string, mixed>> $problems RowProblem::toArray() shape
     * @param int                        $rejected how many rows the job rejected altogether, which
     *                                             is more than were rendered when the cap was hit
     * @param int                        $limit    the cap the rows were read under, 0 when uncapped
     */
    public static function render(array $problems, int $rejected = 0, int $limit = 0): string
    {
        $policy = CellSafetyPolicy::spreadsheetSafe();
        $lines = [self::line(array_map($policy->apply(...), self::HEADER))];

        foreach ($problems as $payload) {
            $problem = RowProblem::fromArray($payload);

            $lines[] = self::line([
                $policy->apply($problem->file),
                (string) $problem->row,
                $policy->apply($problem->column ?? ''),
                $policy->apply($problem->code),
                $policy->apply($problem->outcome),
                $policy->apply($problem->message),
                // Already neutralised on the way in. See the class docblock.
                $problem->value,
            ]);
        }

        if (self::wasTruncated(\count($problems), $rejected, $limit)) {
            $lines[] = self::line([
                '',
                '0',
                '',
                self::TRUNCATED_CODE,
                '',
                sprintf(
                    'This file holds the first %d of %d rejected rows. Fix these, run the same source again,'
                        . ' and download the rows that are still rejected.',
                    \count($problems),
                    $rejected,
                ),
                '',
            ]);
        }

        return self::BOM . implode(self::EOL, $lines) . self::EOL;
    }

    /**
     * Whether a body of this many rows left rows behind.
     *
     * The handler asks the same question to decide whether to send the two
     * headers, so the answer is computed once here rather than twice with the
     * comparison written two ways.
     */
    public static function wasTruncated(int $rendered, int $rejected, int $limit): bool
    {
        return $limit > 0 && $rejected > $rendered && $rendered >= $limit;
    }

    /**
     * The headers a capped download carries, empty when it carried everything.
     *
     * @return array<string, string>
     */
    public static function headersFor(int $rendered, int $rejected, int $limit): array
    {
        if (!self::wasTruncated($rendered, $rejected, $limit)) {
            return [];
        }

        return [
            self::TRUNCATED_HEADER => (string) $rejected,
            self::LIMIT_HEADER => (string) $limit,
        ];
    }

    /**
     * @param list<string> $cells
     */
    private static function line(array $cells): string
    {
        return implode(',', array_map(self::field(...), $cells));
    }

    private static function field(string $value): string
    {
        if (strpbrk($value, ",\"\r\n") === false) {
            return $value;
        }

        return '"' . str_replace('"', '""', $value) . '"';
    }
}
