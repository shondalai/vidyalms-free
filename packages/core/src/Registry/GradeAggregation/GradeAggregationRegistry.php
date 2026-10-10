<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\GradeAggregation;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/**
 * @extends AbstractProviderRegistry<GradeAggregationInterface>
 */
final class GradeAggregationRegistry extends AbstractProviderRegistry
{
    public const EXTENSION_POINT = 'grade_aggregation';

    protected function providerInterface(): string
    {
        return GradeAggregationInterface::class;
    }

    /**
     * The total, with the miss direction applied here rather than in a caller
     * that would get it backwards.
     *
     * An unresolved key answers null and every cell is marked `unknown`, so a
     * screen can say which provider is missing. It never falls back to another
     * strategy: a total computed under a rule nobody asked for is worse than no
     * total, because nobody can tell it happened.
     *
     * @param list<GradeCell> $cells
     */
    public function total(string $key, array $cells, GradeAggregationContext $context): ?CategoryTotal
    {
        if (!$this->has($key)) {
            return null;
        }

        return $this->get($key)->aggregate($cells, $context);
    }

    /**
     * What the cells read as when nothing could fold them.
     *
     * @param list<GradeCell> $cells
     *
     * @return array<string, string>
     */
    public static function unknownStatuses(array $cells): array
    {
        $statuses = [];

        foreach ($cells as $cell) {
            $statuses[$cell->itemKey] = GradeCell::STATUS_UNKNOWN;
        }

        return $statuses;
    }
}
