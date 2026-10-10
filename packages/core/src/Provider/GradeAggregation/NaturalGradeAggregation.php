<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\GradeAggregation;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\CategoryTotal;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationContext;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationInterface;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeCell;

/**
 * Points sum: the category total is the sum of the counted grades and the
 * category maximum is the sum of their maxima.
 *
 * It is Moodle's `natural` and it is the default for the same reason: it is
 * the only strategy whose answer a learner can reproduce by adding up the
 * marks in front of them, and a total nobody can reproduce is a total somebody
 * disputes.
 *
 * The maximum is the sum of the maxima of the cells that **counted**, so
 * excluding a cell removes its marks from both halves of the fraction rather
 * than making the rest of the category unreachable.
 */
final class NaturalGradeAggregation implements GradeAggregationInterface
{
    public const KEY = 'natural';

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.grade_aggregation.natural');
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
        $sum = 0.0;
        $maximum = 0.0;
        $counted = 0;

        foreach ($cells as $cell) {
            if (!$cell->counts($context->emptyAsZero)) {
                $status[$cell->itemKey] = $cell->excluded ? GradeCell::STATUS_DROPPED : GradeCell::STATUS_NOVALUE;
                continue;
            }

            $status[$cell->itemKey] = GradeCell::STATUS_USED;
            $weights[$cell->itemKey] = $cell->weightBp;
            $sum += $cell->effectiveGrade() - $cell->rawGradeMin;
            $maximum += $cell->rawGradeMax - $cell->rawGradeMin;
            $counted++;
        }

        if ($counted === 0 || $maximum <= 0.0) {
            // The declared range when the category has one, so a category with
            // nothing counted reports the same maximum here as `mean` does
            // over the same cells. Two strategies answering different maxima
            // for one empty category is two answers to one question.
            return CategoryTotal::nothingCounted($context->categoryMax ?? $maximum, $status);
        }

        return CategoryTotal::of(
            $context->intoRange($sum / $maximum, $maximum),
            $context->categoryMax ?? $maximum,
            $status,
            $weights,
        );
    }
}
