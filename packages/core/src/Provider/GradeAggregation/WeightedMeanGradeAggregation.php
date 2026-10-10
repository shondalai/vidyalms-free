<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\GradeAggregation;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\CategoryTotal;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationContext;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationInterface;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeCell;

/**
 * The mean of the counted grades, each normalised to its own maximum and then
 * weighted by the weight the item carries.
 *
 * The weight is already on the cell, in basis points, because
 * {@see GradeCell} was shaped for this from the first commit: a float weight
 * is a total that does not add up and nobody can say why. So this provider
 * takes no parameters of its own. Setting the weights is authoring the items,
 * not configuring the strategy.
 *
 * A cell whose weight is zero is counted and contributes nothing, which is not
 * the same as excluding it: its status stays `used` and it appears in the
 * explanation with a zero weight, so a learner asking why a mark did not move
 * their total gets an answer.
 *
 * **When every counted weight is zero the total is empty rather than a
 * division by zero.** That is a real configuration: a category whose items are
 * all set to zero weight has no defined mean, and answering `nothing counted`
 * is the honest form of it.
 */
final class WeightedMeanGradeAggregation implements GradeAggregationInterface
{
    public const KEY = 'weighted-mean';

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.grade_aggregation.weighted_mean');
    }

    public function paramsSchema(): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => []];
    }

    public function currentShape(): int
    {
        return 1;
    }

    public function aggregate(array $cells, GradeAggregationContext $context): CategoryTotal
    {
        $status = [];
        $weights = [];
        $weighted = 0.0;
        $weightTotal = 0;
        $maximum = 0.0;

        foreach ($cells as $cell) {
            if (!$cell->counts($context->emptyAsZero)) {
                $status[$cell->itemKey] = $cell->excluded ? GradeCell::STATUS_DROPPED : GradeCell::STATUS_NOVALUE;

                continue;
            }

            $maximum += $cell->rawGradeMax - $cell->rawGradeMin;
            $status[$cell->itemKey] = GradeCell::STATUS_USED;
            $weights[$cell->itemKey] = $cell->weightBp;
            $weighted += $cell->normalised() * $cell->weightBp;
            $weightTotal += $cell->weightBp;
        }

        if ($weightTotal <= 0) {
            return CategoryTotal::nothingCounted($context->categoryMax ?? $maximum, $status);
        }

        $range = $context->categoryMax ?? $maximum;

        return CategoryTotal::of(
            $context->intoRange($weighted / $weightTotal, $range),
            $range,
            $status,
            $weights,
        );
    }
}
