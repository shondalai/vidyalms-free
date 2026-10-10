<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\GradeAggregation;

/**
 * What a category aggregator is told about the category it is folding.
 *
 * The normalise-then-map step lives here rather than in each provider, because
 * every weighted strategy needs it and nine copies of one arithmetic is nine
 * places for it to disagree.
 */
final class GradeAggregationContext
{
    /**
     * @param array<string, mixed> $parameters the provider's own stored
     *                                         parameters at the version the
     *                                         category was configured at
     */
    private function __construct(
        public readonly string $categoryKey,
        /**
         * Whether an empty grade counts as zero or is left out. Per category,
         * never global: the ambiguity is real and one global answer is wrong
         * for half the sites that have one.
         */
        public readonly bool $emptyAsZero,
        public readonly float $categoryMin,
        public readonly ?float $categoryMax,
        public readonly array $parameters,
        public readonly int $parametersVersion,
    ) {
    }

    /** @param array<string, mixed> $parameters */
    public static function create(
        string $categoryKey,
        bool $emptyAsZero = false,
        float $categoryMin = 0.0,
        ?float $categoryMax = null,
        array $parameters = [],
        int $parametersVersion = 1,
    ): self {
        return new self($categoryKey, $emptyAsZero, $categoryMin, $categoryMax, $parameters, $parametersVersion);
    }

    /**
     * Maps a 0..1 fraction into the category's own range.
     *
     * With no declared maximum the range is the sum of the item maxima, which
     * is what `natural` means, so the caller passes that in.
     */
    public function intoRange(float $fraction, float $fallbackMax): float
    {
        $max = $this->categoryMax ?? $fallbackMax;

        return $this->categoryMin + $fraction * ($max - $this->categoryMin);
    }
}
