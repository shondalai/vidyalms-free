<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

/**
 * One part of a composite item: its stable id, its share of the item's
 * points, and whether a human has to mark it.
 *
 * The weight is a share rather than an absolute, because the item's points
 * are frozen on the attempt and the parts must add up to whatever that frozen
 * value is. A part maximum is therefore derived at grading time, which is
 * what keeps reweighting the item from silently changing what a grader may
 * award a part of an attempt already submitted.
 */
final class QuestionPart
{
    public function __construct(
        public readonly string $id,
        public readonly float $weight = 1.0,
        public readonly bool $requiresHumanGrader = false,
        public readonly ?string $label = null,
    ) {
        if ($weight < 0.0) {
            throw new \InvalidArgumentException('A part weight cannot be negative.');
        }
    }

    /**
     * This part's maximum against an item maximum, given the total weight of
     * the item's parts.
     */
    public function maximumOf(float $itemPoints, float $totalWeight): float
    {
        if ($totalWeight <= 0.0) {
            return 0.0;
        }

        return round($itemPoints * ($this->weight / $totalWeight), 6);
    }
}
