<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * A fact set whose load depends on which subjects the render actually covers.
 *
 * Default when absent: the loader answers from load() and the subject keys
 * are not its business, which is true of progress, grades and entitlements
 * alike (all three are per learner and per course).
 *
 * It is a capability probed with instanceof rather than a widened load()
 * signature, because widening a shipped method breaks every implementation
 * that already exists. A date-window loader needs it: the merge that layers
 * overrides over a run's schedule is keyed by target, so answering "every
 * window in the site" is not an option and "every window in the course" is
 * not the same query.
 */
interface SubjectAwareFactLoader
{
    /**
     * @param list<int>    $courseIds   the courses in the render set
     * @param list<string> $subjectKeys the render set's subjects as "type:id"
     */
    public function loadForSubjects(AvailabilityContext $context, array $courseIds, array $subjectKeys): mixed;
}
