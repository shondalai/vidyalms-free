<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Report;

use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;

/**
 * A report that is a header and N rows.
 *
 * **Only a tabular report is exportable.** `course-overview` returns six
 * scalars and can never be a CSV; a roster returns thousands of rows and must
 * never be assembled in memory. Without the split the export code has to guess
 * whether an array is a table.
 *
 * Default when absent: the report is scalar and is absent from the export
 * routes, which is the miss direction the standards page publishes for
 * `ExportFormat` applied one level up.
 *
 * `rows()` returns an iterable and is expected to be a generator, so the
 * export layer streams record by record at roughly fixed memory rather than
 * materialising the result. A provider that returns an array still works and
 * pays for it in memory.
 */
interface TabularReportInterface extends ReportDefinitionInterface
{
    /** @return list<ColumnDefinition> */
    public function columns(ReportContext $context): array;

    /**
     * The rows, in the order the columns describe.
     *
     * Page by keyset, never by offset: offset makes the database build and
     * discard every skipped row and lets rows shift between pages under
     * concurrent inserts, producing duplicates and gaps in an export that runs
     * while `activity_log` heartbeats insert. The cursor lives on
     * {@see ReportContext} so no provider invents its own.
     *
     * @return iterable<array<string, mixed>>
     */
    public function rows(ReportContext $context): iterable;
}
