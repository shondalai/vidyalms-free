<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

/**
 * The admin enrolments list: one page plus the facet counts the filter chips
 * show. The facets are site-wide and ignore the current filters, because a
 * chip that only counted what is already filtered would always read zero
 * once you clicked another one.
 */
final class SiteEnrolmentPage
{
    public function __construct(
        public readonly LearnerEnrolmentPage $page,
        public readonly int $activeCount,
        public readonly int $expiringCount,
        public readonly int $suspendedCount,
        public readonly int $expiringWithinDays,
    ) {
    }
}
