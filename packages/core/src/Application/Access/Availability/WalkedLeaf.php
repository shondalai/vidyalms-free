<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityLeaf;

/**
 * A leaf found during the tree walk, with the negation that reached it.
 */
final class WalkedLeaf
{
    public function __construct(
        public readonly AvailabilityLeaf $leaf,
        public readonly bool $negated,
    ) {
    }
}
