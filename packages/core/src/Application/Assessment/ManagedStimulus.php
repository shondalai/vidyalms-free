<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Stimulus;

/**
 * A stimulus as the authoring surface shows it: the row plus how many
 * questions point at it, which is what the list displays and what the
 * removal refuses on.
 */
final class ManagedStimulus
{
    public function __construct(
        public readonly Stimulus $stimulus,
        public readonly int $questionCount,
    ) {
    }
}
