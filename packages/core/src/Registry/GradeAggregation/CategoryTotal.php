<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\GradeAggregation;

/**
 * A category total that can be explained rather than asserted.
 *
 * The per-cell outcome is carried beside the number because a total nobody can
 * account for is a grade dispute waiting to happen. Moodle stores
 * `aggregationstatus` and `aggregationweight` per cell for exactly this reason,
 * and the four statuses here are its vocabulary.
 */
final class CategoryTotal
{
    /**
     * @param array<string, string> $cellStatus       item key => one of GradeCell's statuses
     * @param array<string, int>    $cellWeightBp     item key => the weight actually used
     */
    private function __construct(
        public readonly float $value,
        public readonly float $maximum,
        public readonly array $cellStatus,
        public readonly array $cellWeightBp,
        /** True when nothing counted, so a screen says "no marks yet" rather than "zero". */
        public readonly bool $empty,
    ) {
    }

    /**
     * @param array<string, string> $cellStatus
     * @param array<string, int>    $cellWeightBp
     */
    public static function of(float $value, float $maximum, array $cellStatus, array $cellWeightBp): self
    {
        return new self($value, $maximum, $cellStatus, $cellWeightBp, false);
    }

    /**
     * Nothing counted: every cell was empty, excluded or dropped.
     *
     * @param array<string, string> $cellStatus
     */
    public static function nothingCounted(float $maximum, array $cellStatus): self
    {
        return new self(0.0, $maximum, $cellStatus, [], true);
    }

    public function percentage(): ?float
    {
        if ($this->empty || $this->maximum <= 0.0) {
            return null;
        }

        return round($this->value / $this->maximum * 100, 4);
    }
}
