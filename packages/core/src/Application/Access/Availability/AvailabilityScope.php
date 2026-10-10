<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * One learner's availability, handed to the reads that must respect it.
 *
 * It is an optional trailing parameter on the five read methods of
 * LessonAvailability rather than a new class, because a second object
 * answering "is this lesson part of the learner's course" would reintroduce
 * the divergence that class exists to prevent. Null preserves the previous
 * behaviour byte for byte, so every existing call site compiles unchanged and
 * the structural answer stays exactly where it was.
 */
final class AvailabilityScope
{
    public function __construct(
        public readonly int $enrolmentId,
        public readonly AvailabilityContext $context,
        private readonly AvailabilityScopeResolver $resolver,
    ) {
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function countingIds(string $subjectType, array $ids): array
    {
        return $ids === [] ? [] : $this->resolver->countingIds($this->context, $subjectType, $ids);
    }

    /**
     * The denominator question: does this item still count towards
     * completion. It is true for a blocked item the learner can still reach,
     * which is why it is not the question an entry check asks.
     */
    public function allows(string $subjectType, int $id): bool
    {
        return $this->countingIds($subjectType, [$id]) !== [];
    }

    /**
     * The entry question: may this learner act on this item now.
     *
     * Availability enforced only in the player is not enforced, so the write
     * paths ask this rather than the denominator question. A refusal carries
     * the composed reason, so a learner is told the same thing the outline
     * told them rather than being handed a bare 404.
     */
    public function entryDecision(string $subjectType, int $id): AvailabilityDecision
    {
        return $this->resolver->entryDecision($this->context, $subjectType, $id);
    }

    public function permits(string $subjectType, int $id): bool
    {
        return $this->entryDecision($subjectType, $id)->isAvailable();
    }
}
