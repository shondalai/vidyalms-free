<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\GradeAggregation;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\CategoryTotal;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationContext;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationInterface;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeCell;

/**
 * The mean of the counted grades, each normalised to its own maximum first.
 *
 * Normalising before averaging is the whole difference between this and
 * `natural`: a twenty-mark essay and a five-mark quiz contribute equally here
 * and proportionally there, and a gradebook that could only do one of those is
 * wrong for half its categories. The normalise-to-0-to-1-then-map-into-the-range
 * step lives on the context so every later weighted strategy uses the same
 * arithmetic.
 */
final class MeanGradeAggregation implements GradeAggregationInterface
{
    public const KEY = 'mean';

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.grade_aggregation.mean');
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
        $fractions = [];
        $maximum = 0.0;

        foreach ($cells as $cell) {
            if (!$cell->counts($context->emptyAsZero)) {
                $status[$cell->itemKey] = $cell->excluded ? GradeCell::STATUS_DROPPED : GradeCell::STATUS_NOVALUE;
                continue;
            }

            // The fallback range is the counted cells' own maxima, exactly as
            // `natural` computes it. Accumulating before the test made the two
            // free strategies disagree about the maximum of one set of cells:
            // excluding a cell removed its marks from natural's range and left
            // them in mean's, so the same gradebook read 80 of 100 under one
            // and 160 of 200 under the other.
            $maximum += $cell->rawGradeMax - $cell->rawGradeMin;
            $status[$cell->itemKey] = GradeCell::STATUS_USED;
            $weights[$cell->itemKey] = $cell->weightBp;
            $fractions[] = $cell->normalised();
        }

        if ($fractions === []) {
            return CategoryTotal::nothingCounted($context->categoryMax ?? $maximum, $status);
        }

        $mean = array_sum($fractions) / \count($fractions);
        $range = $context->categoryMax ?? $maximum;

        return CategoryTotal::of($context->intoRange($mean, $range), $range, $status, $weights);
    }
}
