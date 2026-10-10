<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTree;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;

/**
 * One entry of a render set: a tree and the row it hangs on.
 */
final class SubjectTree
{
    public function __construct(
        public readonly AvailabilityTree $tree,
        public readonly ?GateSubject $subject,
    ) {
    }
}
