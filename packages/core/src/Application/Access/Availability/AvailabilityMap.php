<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;

/**
 * The composed answer for a whole render set, keyed by subject.
 *
 * A subject nobody asked about answers available, which is what a free build
 * with no rules stored answers everywhere: the shape ships before the feature
 * and adding a restriction is never a breaking contract change.
 */
final class AvailabilityMap
{
    /**
     * @param array<string, AvailabilityDecision> $decisions
     */
    public function __construct(private readonly array $decisions = [])
    {
    }

    public static function open(): self
    {
        return new self([]);
    }

    public function for(GateSubject $subject): AvailabilityDecision
    {
        return $this->decisions[$subject->key()] ?? AvailabilityDecision::open();
    }

    public function isVisible(GateSubject $subject): bool
    {
        return !$this->for($subject)->isHidden();
    }

    public function isAvailable(GateSubject $subject): bool
    {
        return $this->for($subject)->isAvailable();
    }

    /**
     * The denominator rule applied to one subject: an item counts when it is
     * available, or blocked and not exhausted.
     */
    public function counts(GateSubject $subject): bool
    {
        return $this->for($subject)->countsTowardsCompletion();
    }

    /**
     * The subset of ids of one type that count towards completion, which is
     * the id set both the numerator and the denominator are read from.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function countingIds(string $subjectType, array $ids): array
    {
        $counting = [];

        foreach ($ids as $id) {
            if ($this->counts(new GateSubject($subjectType, $id))) {
                $counting[] = $id;
            }
        }

        return $counting;
    }

    /**
     * @return array<string, AvailabilityDecision>
     */
    public function all(): array
    {
        return $this->decisions;
    }
}
