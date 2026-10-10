<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityMap;

/**
 * The outline one learner sees: the sections that survived both the
 * publication rules and the restriction engine, and the composed access
 * answer for each row that did.
 *
 * A hidden item is absent from the sections rather than present with a flag,
 * because a client given a flag will eventually render it and the title
 * itself is the leak: a reason string discloses the existence and the name of
 * a gated assessment.
 */
final class CurriculumView
{
    /**
     * @param list<CurriculumSection> $sections
     */
    public function __construct(
        public readonly array $sections,
        public readonly AvailabilityMap $access,
    ) {
    }
}
