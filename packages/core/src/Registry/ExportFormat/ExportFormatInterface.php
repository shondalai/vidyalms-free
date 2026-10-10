<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ExportFormat;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;

/**
 * One serialisation of a tabular report.
 *
 * Free ships `csv` and `json`; Pro adds `xlsx` and `pdf`; a Business package
 * adds `ods` or a warehouse format with no core edit. The rule the item layer
 * already states is the rule here: the export route offers exactly the formats
 * the registry answers with, which is what makes a fourth format a
 * registration rather than an edit to the export path.
 *
 * **Miss direction: absent from the negotiated set.** A caller that names no
 * format is written in the site's default and never refused. A caller that
 * explicitly named an unregistered one is refused 422 with the list of what
 * the site can write, because an export format is a named request parameter
 * rather than an `Accept` header, and quietly writing a CSV for a request that
 * said `xlsx` hands somebody a file their tool cannot open with nothing
 * anywhere saying why.
 */
interface ExportFormatInterface extends ProviderInterface
{
    public function label(): string;

    /** The picker group, so a screen can order formats without knowing them. */
    public function group(): string;

    public function order(): int;

    /** Without the leading dot. */
    public function extension(): string;

    public function mimeType(): string;

    /**
     * Writes the rows out, streaming.
     *
     * The rows arrive as an iterable and are consumed once. A writer that
     * materialises them into an array is holding a gradebook in memory, which
     * is the mistake Moodle records having made in its own Excel and ODS
     * writers.
     *
     * @param iterable<array<string, mixed>> $rows
     * @param list<ColumnDefinition>         $columns
     */
    public function write(iterable $rows, array $columns, ExportContext $context, StreamWriter $out): void;
}
