<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityLeaf;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;

/**
 * One leaf as phase one collected it: the leaf, the negation the operators
 * pushed down to it, and the subject it hangs on.
 */
final class CollectedLeaf
{
    public function __construct(
        public readonly AvailabilityLeaf $leaf,
        public readonly bool $negated,
        public readonly ?GateSubject $subject,
    ) {
    }
}
