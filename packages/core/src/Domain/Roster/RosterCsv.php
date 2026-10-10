<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Roster;

/**
 * The CSV rules the OneRoster binding fixes, in one place, because an
 * implementer who reads them out of the specification twice implements them
 * twice.
 *
 * - UTF-8, with a leading BOM (EF BB BF) tolerated and ignored. Every
 *   spreadsheet on Windows writes one and no reader may choke on it; a BOM left
 *   on the first header name is why an importer reports "there is no column
 *   called sourcedId" about a file whose first column is called sourcedId.
 * - RFC 4180 quoting: a field holding a comma or a double quote is wrapped in
 *   double quotes and an embedded double quote is doubled. PHP's own
 *   str_getcsv() implements exactly this once the backslash escape is turned
 *   off, which is why the escape argument below is the empty string: with the
 *   default backslash, a Windows path in a cell eats the quote after it.
 * - CRLF or LF line endings, and a quoted field may contain either.
 * - A mandatory, case-sensitive header row in the specified column order, with
 *   extension columns appended only at the right. Case sensitivity is the
 *   specification's, and it is kept: a file whose header says `SourcedId` is a
 *   file some other tool will read differently, and silently accepting it here
 *   makes this product the odd one out.
 * - Booleans as the strings `true` and `false`, never native booleans.
 * - Dates as YYYY-MM-DD, years as YYYY, and dateLastModified in the W3C ISO
 *   8601 UTC profile.
 * - Multi-valued fields comma delimited inside one double-quoted column.
 * - A file with no data rows is not permitted: it is declared absent in the
 *   manifest and omitted from the package.
 */
final class RosterCsv
{
    public const BOM = "\xEF\xBB\xBF";

    private function __construct()
    {
    }

    /**
     * Splits a file into rows, honouring quoted fields that span lines.
     *
     * A line-based split would be shorter and would break the first time
     * somebody's course title contains a newline inside quotes, which is a
     * thing exports do. So the whole file is walked once, in one pass, and the
     * quote state decides whether a line break ends a row or belongs to a
     * field.
     *
     * @return list<list<string>>
     */
    public static function rows(string $bytes): array
    {
        $bytes = self::withoutBom($bytes);
        $rows = [];
        $length = \strlen($bytes);
        $line = '';
        $inQuotes = false;

        for ($index = 0; $index < $length; $index++) {
            $character = $bytes[$index];

            if ($character === '"') {
                $inQuotes = !$inQuotes;
                $line .= $character;

                continue;
            }

            if (!$inQuotes && ($character === "\n" || $character === "\r")) {
                if ($character === "\r" && ($bytes[$index + 1] ?? '') === "\n") {
                    $index++;
                }

                $rows[] = self::cells($line);
                $line = '';

                continue;
            }

            $line .= $character;
        }

        if ($line !== '') {
            $rows[] = self::cells($line);
        }

        // A trailing newline is ordinary and a blank line inside the file is a
        // row of one empty cell, which is neither a header nor data. Dropping
        // it here rather than in each mapper is what keeps a row number
        // meaningful: the row numbers reported to an operator are the file's
        // own line numbers, so the blank line still counts.
        return $rows;
    }

    /**
     * One line into its cells, with RFC 4180 quoting and no backslash escape.
     *
     * @return list<string>
     */
    public static function cells(string $line): array
    {
        return array_values(array_map(
            static fn (?string $cell): string => $cell ?? '',
            str_getcsv(rtrim($line, "\r"), ',', '"', ''),
        ));
    }

    public static function withoutBom(string $bytes): string
    {
        return str_starts_with($bytes, self::BOM) ? substr($bytes, \strlen(self::BOM)) : $bytes;
    }

    /**
     * Whether a row is blank, which is a line and not a record.
     *
     * @param list<string> $cells
     */
    public static function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * The value of one named column, or the empty string when the header has
     * no such column.
     *
     * Reading by name rather than by position is what makes "extension columns
     * appended only at the right" harmless: a file with three extra columns
     * loads exactly as a file without them.
     *
     * @param array<string, int> $header column name to position
     * @param list<string>       $cells
     */
    public static function value(array $header, array $cells, string $column): string
    {
        $at = $header[$column] ?? null;

        return $at === null ? '' : trim($cells[$at] ?? '');
    }

    /**
     * The header row as a name to position map.
     *
     * @param list<string> $cells
     *
     * @return array<string, int>
     */
    public static function header(array $cells): array
    {
        $header = [];

        foreach ($cells as $at => $name) {
            $name = trim(self::withoutBom($name));

            if ($name !== '') {
                $header[$name] = $at;
            }
        }

        return $header;
    }

    /**
     * A OneRoster boolean, which is the string "true" and nothing else.
     *
     * Anything unrecognised is false rather than an error, because the one
     * place this is read is a `primary` flag whose absence means the same as
     * false, and failing a row over it would reject a roster for a cell that
     * changes nothing.
     */
    public static function truth(string $value): bool
    {
        return strtolower(trim($value)) === 'true';
    }

    /**
     * The values of a multi-valued cell, which is comma delimited inside one
     * quoted column.
     *
     * @return list<string>
     */
    public static function multiple(string $value): array
    {
        $parts = array_map('trim', explode(',', $value));

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }
}
