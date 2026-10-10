<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ExportFormat;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\ExportContext;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\ExportFormatInterface;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\StreamWriter;
use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;

/**
 * RFC 4180 CSV, written a row at a time.
 *
 * Three behaviours here are defaults nowhere and are the reason this is a
 * provider rather than a loop in each report:
 *
 * 1. RFC 4180 quoting with doubled quotes and CRLF terminators.
 * 2. {@see \Shondalai\VidyaLMS\Core\Domain\Reporting\CellSafetyPolicy} over the
 *    full OWASP trigger list. Quoting alone does not stop a spreadsheet
 *    evaluating a cell.
 * 3. A byte order mark as a per-request option, because Excel cannot reliably
 *    detect UTF-8 in a .csv without EF BB BF while a strict UTF-8 pipeline
 *    reads the same bytes as first-field corruption.
 *
 * The Excel-only `sep=;` first line is deliberately **not** emitted. It is
 * outside RFC 4180 and breaks strict parsers, and it belongs to an explicit
 * Excel profile rather than to the format everything else in the product reads.
 *
 * It does not declare `SupportsSheetChunking`, so a run past the sheet row
 * limit fails with a stated reason rather than truncating, which is the
 * published direction: a truncated export is one the recipient cannot tell is
 * incomplete.
 */
final class CsvExportFormat implements ExportFormatInterface
{
    public const KEY = 'csv';

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.export.csv');
    }

    public function group(): string
    {
        return 'data';
    }

    public function order(): int
    {
        return 10;
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function mimeType(): string
    {
        return 'text/csv';
    }

    public function write(iterable $rows, array $columns, ExportContext $context, StreamWriter $out): void
    {
        if ($columns === []) {
            throw ValidationException::forField('columns', 'A tabular export needs at least one column.');
        }

        if ($context->byteOrderMark) {
            $out->write("\xEF\xBB\xBF");
        }

        $out->write($this->line(array_map(
            static fn (ColumnDefinition $column): string => $column->label,
            $columns,
        ), $context));

        $written = 0;

        foreach ($rows as $row) {
            $written++;

            if ($written > $context->sheetRowLimit) {
                throw ValidationException::forField('rows', sprintf(
                    'This export is past the %d row limit of one sheet and "%s" cannot split sheets, '
                    . 'so the run is refused rather than silently truncated.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $context->sheetRowLimit,
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    self::KEY,
                ));
            }

            $cells = [];

            foreach ($columns as $column) {
                $cells[] = self::cell($row[$column->key] ?? null, $column, $context);
            }

            $out->write($this->line($cells, $context));
        }
    }

    /**
     * One RFC 4180 record: fields quoted, inner quotes doubled, CRLF at the
     * end.
     *
     * It is written here rather than through fputcsv() because fputcsv writes
     * to a stream resource and the core has no filesystem; the escaping rule is
     * three lines and the seam is worth more than the function.
     *
     * @param list<string> $cells
     */
    private function line(array $cells, ExportContext $context): string
    {
        $safe = array_map(
            static fn (string $value): string => '"' . str_replace('"', '""', $context->cellSafety->apply($value)) . '"',
            $cells,
        );

        return implode(',', $safe) . "\r\n";
    }

    /**
     * One value in the profile the context asked for.
     *
     * The machine profile writes canonical values: RFC 3339 instants with an
     * offset, a dot decimal separator, no thousands separators, and money as
     * integer minor units with the ISO 4217 code in the sibling column the
     * definition named. A single formatted export is what makes an LMS export
     * simultaneously unopenable in a German spreadsheet and unparseable by a
     * script.
     */
    private static function cell(mixed $value, ColumnDefinition $column, ExportContext $context): string
    {
        if ($value === null) {
            return '';
        }

        if (\is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if ($column->type === ColumnDefinition::TYPE_DECIMAL && is_numeric($value)) {
            return $context->isMachineProfile()
                ? rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.')
                : (string) $value;
        }

        if (\is_array($value)) {
            return (string) json_encode($value, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
    }
}
