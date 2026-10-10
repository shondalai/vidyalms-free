<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

final class GradebookPage
{
    /**
     * @param list<GradebookItem> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
        public readonly int $pendingTotal,
        public readonly int $gradedTotal,
    ) {
    }
}
