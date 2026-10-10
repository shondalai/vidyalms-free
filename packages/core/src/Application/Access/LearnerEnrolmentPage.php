<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

final class LearnerEnrolmentPage
{
    /**
     * @param list<LearnerEnrolment> $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $total,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }
}
