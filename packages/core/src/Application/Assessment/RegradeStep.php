<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

/**
 * What a regrade did to one attempt: how many grade rows it appended, and
 * the before-and-after entry when the attempt's own total moved.
 */
final class RegradeStep
{
    public function __construct(
        public readonly int $appended,
        public readonly ?RegradeAttemptChange $change,
    ) {
    }
}
