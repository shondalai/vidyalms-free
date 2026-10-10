<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

/**
 * One page of an import's row problems.
 *
 * The rows cross this boundary as maps rather than as objects, in the same
 * shape Application\Platform\RowProblem serialises to and reads back from.
 * That is deliberate and it is the only shape available: the row problem value
 * object composes its message through ImportProblem and bounds its excerpt
 * through ImportNote, both of which are application services, and the domain
 * does not import the application layer in this codebase. The alternative
 * would be a second row problem model living down here for the repository to
 * speak, which is two vocabularies for one fact.
 *
 * ImportJobRepository already crosses the same boundary the same way with its
 * totals map, so this is the existing convention rather than a new one.
 */
final class ImportRowProblemPage
{
    /**
     * @param list<array<string, mixed>> $items    RowProblem::toArray() shape, worst rows in file order
     * @param array<string, int>         $byCode   how many rows carry each code, over the whole job
     * @param int                        $rejected how many rows of the job were skipped or failed
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly array $byCode = [],
        public readonly int $rejected = 0,
    ) {
    }
}
