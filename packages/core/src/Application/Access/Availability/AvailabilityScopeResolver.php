<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * What an availability scope delegates to.
 *
 * The seam exists so LessonAvailability can be scoped without becoming a
 * second evaluator. Its docblock records the bug that made it necessary: the
 * curriculum view and the completion count once asked separately, so a
 * published lesson in an archived module was invisible and still counted, and
 * the course could not be finished. A second object answering the same
 * question would reintroduce it.
 */
interface AvailabilityScopeResolver
{
    /**
     * The subset of ids that count towards completion: available, or blocked
     * and not exhausted.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function countingIds(AvailabilityContext $context, string $subjectType, array $ids): array;

    /**
     * The composed decision for one subject: may this learner enter.
     *
     * It is a different question from countingIds() and conflating the two is
     * how a blocked item stayed writable. The denominator counts an item that
     * is blocked but still reachable by the clock or by the learner's own
     * work, because it is work the learner still owes; entry refuses it. A
     * use case that asked the denominator question let a learner post a
     * heartbeat to a drip-locked lesson, complete it, and finish the course
     * early with a certificate to show for it.
     */
    public function entryDecision(AvailabilityContext $context, string $subjectType, int $id): AvailabilityDecision;
}
