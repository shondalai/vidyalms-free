<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;

/**
 * The progress sink, asked whether it can take a row at a time.
 *
 * A capability interface on the sink rather than two more methods on
 * ImportProgress, for the same reason every capability in this codebase is
 * one: ImportProgress is shipped, a third-party importer has implemented it,
 * and adding a method to it would break that importer on upgrade. An importer
 * that wants the row report probes:
 *
 * ```php
 * if ($progress instanceof RecordsRowProblems) {
 *     $progress->rowProblem($problem);
 * }
 * ```
 *
 * and an importer that does not care keeps reporting notes and totals exactly
 * as it does today. Extending AbstractImporter does the probe for you.
 *
 * The two methods together are what makes the per-file summary possible. A
 * sink told only about the bad rows can say how many were bad and nothing
 * about how many there were, so the Canvas-shaped summary note (N rows, X
 * created, Y updated, Z skipped, W failed) would have to be composed by every
 * mapper separately and would be composed differently by each of them.
 */
interface RecordsRowProblems
{
    /**
     * One row that went wrong, recorded against the job.
     *
     * The problem carries its own file, row and outcome, so this also counts
     * that row into the per-file summary and into the per-outcome totals: a
     * mapper never reports the same row twice.
     */
    public function rowProblem(RowProblem $problem): void;

    /**
     * One row that went fine.
     *
     * @param string $file    the CSV name inside the zip, or '' for a single file
     * @param string $outcome one of the RowProblem OUTCOME_* constants
     */
    public function rowOutcome(string $file, string $outcome): void;
}
