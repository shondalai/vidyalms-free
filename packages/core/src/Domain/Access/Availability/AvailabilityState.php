<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access\Availability;

/**
 * The three-valued answer a condition, a node or a whole tree gives.
 *
 * NEVER is defined precisely because the completion denominator depends on
 * it: a leaf returns NEVER when it cannot become satisfiable by time passing
 * or by the learner working, but only by an administrative act. That
 * definition is what makes the excluded set stable between administrative
 * changes, which is what makes it cacheable, and it is why only a provider
 * implementing FiltersLearners may return it.
 *
 * Two of the three reach a learner. To a learner an item that will never
 * open and an item whose window has closed both read as blocked with a
 * reason; publishing the distinction would say which exclusions were
 * administrative.
 */
enum AvailabilityState: string
{
    case Available = 'available';
    case Blocked = 'blocked';
    case Never = 'never';

    /**
     * What a learner-facing payload may say. NEVER is a denominator fact and
     * is never published.
     */
    public function learnerFacing(): string
    {
        return $this === self::Available ? 'available' : 'blocked';
    }
}
