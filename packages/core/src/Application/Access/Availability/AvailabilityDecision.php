<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome;

/**
 * The composed answer for one subject: the outcome, and whether the item is
 * shown at all.
 *
 * Visibility is separate from the outcome because a hidden item is absent
 * from the payload rather than present with a flag. A React app given a flag
 * will eventually render it, and the title itself is the leak: a reason
 * string discloses the existence and the name of a gated assessment.
 */
final class AvailabilityDecision
{
    public function __construct(
        public readonly AvailabilityOutcome $outcome,
        public readonly bool $visible = true,
    ) {
    }

    public static function open(): self
    {
        return new self(AvailabilityOutcome::available());
    }

    public function isAvailable(): bool
    {
        return $this->outcome->isAvailable();
    }

    /** A hidden blocked item is omitted; a hidden available item is shown. */
    public function isHidden(): bool
    {
        return !$this->visible && !$this->outcome->isAvailable();
    }

    public function countsTowardsCompletion(): bool
    {
        return $this->outcome->countsTowardsCompletion();
    }
}
