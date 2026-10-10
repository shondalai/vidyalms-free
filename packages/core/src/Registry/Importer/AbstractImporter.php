<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

use Shondalai\VidyaLMS\Core\Application\Platform\ImportRecorder;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * The base every shipped importer extends: implement importWithProgress() and
 * both halves of the contract are satisfied. Called outside a job, import()
 * collects the same notes and totals into a detached recorder and returns
 * them in one piece.
 */
abstract class AbstractImporter implements ProgressiveImporterInterface
{
    final public function import(string $sourcePath, array $options, Principal $principal): array
    {
        $recorder = new ImportRecorder(0, (bool) ($options['dry_run'] ?? false));

        $this->importWithProgress($sourcePath, $options, $principal, $recorder);
        $recorder->summariseFiles();

        return $recorder->result()->toArray();
    }

    /**
     * Reports one bad row, if the sink can take one.
     *
     * The probe is here rather than in every importer because it is the same
     * three lines in each of them, and because an importer that forgets it
     * loses the row report silently: a sink that cannot record rows is the
     * ordinary case outside a job, not an error. A caller that has already
     * probed may of course call the sink directly.
     */
    final protected function reportRow(ImportProgress $progress, RowProblem $problem): void
    {
        if ($progress instanceof RecordsRowProblems) {
            $progress->rowProblem($problem);
        }
    }

    /**
     * Reports one row that went fine, so the per-file summary counts it.
     *
     * A summary built only from the bad rows can say how many were bad and
     * nothing about how many there were, and "40 failed" without "39,960
     * created" beside it is the wrong sentence to hand an operator.
     *
     * @param string $outcome one of the RowProblem OUTCOME_* constants
     */
    final protected function reportOutcome(ImportProgress $progress, string $file, string $outcome): void
    {
        if ($progress instanceof RecordsRowProblems) {
            $progress->rowOutcome($file, $outcome);
        }
    }
}
