<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\RegradePolicy;

/**
 * Exactly what a regrade did, or would do.
 *
 * "Exactly" is the requirement: an instructor about to move a cohort's marks
 * is entitled to a list of the attempts that move and by how much, before
 * and after, and a dry run has to answer the identical question without
 * writing anything.
 */
final class RegradeOutcome
{
    /** @var list<RegradeAttemptChange> */
    public readonly array $items;

    /** @var list<string> */
    public readonly array $warnings;

    /**
     * @param list<RegradeAttemptChange> $items
     * @param list<string>               $warnings
     */
    public function __construct(
        public readonly RegradePolicy $policy,
        public readonly bool $dryRun,
        public readonly int $attemptsExamined,
        public readonly int $attemptsChanged,
        public readonly int $gradesAppended,
        public readonly int $questionsRegraded,
        array $items = [],
        array $warnings = [],
    ) {
        $this->items = $items;
        $this->warnings = array_values(array_unique($warnings));
    }
}
