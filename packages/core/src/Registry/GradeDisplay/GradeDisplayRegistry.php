<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\GradeDisplay;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<GradeDisplayInterface>
 */
final class GradeDisplayRegistry extends AbstractProviderRegistry
{
    public const EXTENSION_POINT = 'grade_display';

    protected function providerInterface(): string
    {
        return GradeDisplayInterface::class;
    }

    /**
     * The grade as text, with the miss direction applied here rather than in a
     * caller that would get it backwards.
     *
     * An unresolved key falls back to the raw number, which is the opposite of
     * what the aggregation registry does with a missing key and is correct for
     * the opposite reason: a display provider cannot change a total, so the
     * worst a fallback can do is show the right number in the wrong notation.
     * A missing aggregator can only be guessed at, so that one refuses.
     */
    public function render(string $key, float $value, float $maximum, ?float $percentage, int $decimals): string
    {
        if (!$this->has($key)) {
            return number_format($value, max($decimals, 0), '.', '');
        }

        return $this->get($key)->format($value, $maximum, $percentage, $decimals);
    }
}
