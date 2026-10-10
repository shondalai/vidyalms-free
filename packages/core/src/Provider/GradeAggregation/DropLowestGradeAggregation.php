<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\GradeAggregation;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\CategoryTotal;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationContext;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationInterface;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeCell;

/**
 * The mean of the counted grades with the worst `drop` of them left out.
 *
 * The commonest real gradebook rule after the plain mean, and the reason
 * `GradeCell::STATUS_DROPPED` exists: the dropped cells are named in the
 * explanation rather than silently missing, because a learner who sees a mark
 * on their transcript and not in their total has to be told which rule removed
 * it.
 *
 * **Dropping is by normalised fraction, never by raw points.** Five out of ten
 * is worse than fifteen out of thirty, and a rule comparing 5 with 15 would
 * drop the wrong one on any category whose items have different maxima, which
 * is most of them.
 *
 * **At least one cell always survives.** Asking to drop three of three is a
 * configuration a screen should refuse, but a total of "nothing counted" for a
 * learner who submitted everything is the worse of the two answers, so the
 * count is clamped rather than obeyed.
 *
 * The count is the provider's own parameter and not the `grade_items.drop_lowest`
 * column: the column is what an authoring screen writes, and the writer maps it
 * into these parameters, which is what keeps every strategy's configuration in
 * one JSON shape with one version behind it.
 */
final class DropLowestGradeAggregation implements GradeAggregationInterface
{
    public const KEY = 'drop-lowest';

    public const PARAM_DROP = 'drop';

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.grade_aggregation.drop_lowest');
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                self::PARAM_DROP => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'default' => 0,
                    'description' => 'How many of the lowest counted grades to leave out of the mean.',
                ],
            ],
        ];
    }

    public function currentShape(): int
    {
        return 1;
    }

    public function aggregate(array $cells, GradeAggregationContext $context): CategoryTotal
    {
        $status = [];
        $counted = [];

        foreach ($cells as $cell) {
            if (!$cell->counts($context->emptyAsZero)) {
                $status[$cell->itemKey] = $cell->excluded ? GradeCell::STATUS_DROPPED : GradeCell::STATUS_NOVALUE;

                continue;
            }

            $counted[] = $cell;
        }

        if ($counted === []) {
            return CategoryTotal::nothingCounted($context->categoryMax ?? 0.0, $status);
        }

        $drop = min($this->drop($context), \count($counted) - 1);

        // Sorted by fraction so a five-out-of-ten is compared with a
        // fifteen-out-of-thirty on the same scale. Ties break on the item key
        // so two runs of one gradebook drop the same cell.
        usort($counted, static function (GradeCell $a, GradeCell $b): int {
            return $a->normalised() <=> $b->normalised() ?: strcmp($a->itemKey, $b->itemKey);
        });

        $weights = [];
        $fractions = [];
        $maximum = 0.0;

        foreach ($counted as $index => $cell) {
            if ($index < $drop) {
                $status[$cell->itemKey] = GradeCell::STATUS_DROPPED;

                continue;
            }

            $status[$cell->itemKey] = GradeCell::STATUS_USED;
            $weights[$cell->itemKey] = $cell->weightBp;
            $fractions[] = $cell->normalised();
            $maximum += $cell->rawGradeMax - $cell->rawGradeMin;
        }

        $range = $context->categoryMax ?? $maximum;
        $mean = array_sum($fractions) / \count($fractions);

        return CategoryTotal::of($context->intoRange($mean, $range), $range, $status, $weights);
    }

    private function drop(GradeAggregationContext $context): int
    {
        $value = $context->parameters[self::PARAM_DROP] ?? 0;

        return \is_int($value) && $value > 0 ? $value : 0;
    }
}
