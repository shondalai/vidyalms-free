<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\GradeAggregation;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\CategoryTotal;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationContext;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeAggregationInterface;
use Shondalai\VidyaLMS\Core\Registry\GradeAggregation\GradeCell;

/**
 * A points sum in which some items add marks without adding to the maximum.
 *
 * Extra credit is the one gradebook rule that cannot be expressed as a weight.
 * A bonus item worth five marks on a category out of a hundred has to raise
 * the numerator and leave the denominator alone, so a learner scoring
 * everything plus the bonus reads 100 and not 105 out of 105. A weight of zero
 * removes the marks; a weight above zero moves the maximum. Neither is what an
 * instructor means by "bonus".
 *
 * **The total is capped at the category maximum**, which is the behaviour every
 * gradebook that has this feature settles on. Uncapped, a category whose bonus
 * items outnumber its real ones stops being a percentage of anything, and the
 * course total that folds it becomes unreadable.
 *
 * **Which items are extra credit is a parameter of the strategy, not a field on
 * the cell.** `grade_items.extra_credit` is what an authoring screen writes and
 * the reader maps it into these parameters, for the same reason `drop-lowest`
 * takes its count here: every strategy's configuration is one JSON shape with
 * one version behind it, and a flag on the shared cell would make it a column
 * every future strategy has to have an opinion about.
 */
final class ExtraCreditGradeAggregation implements GradeAggregationInterface
{
    public const KEY = 'extra-credit';

    public const PARAM_ITEMS = 'extra_credit_items';

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.grade_aggregation.extra_credit');
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                self::PARAM_ITEMS => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 190],
                    'maxItems' => 200,
                    'description' => 'Item keys whose marks add to the total without adding to the maximum.',
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
        $bonus = $this->bonusKeys($context);
        $status = [];
        $weights = [];
        $earned = 0.0;
        $maximum = 0.0;
        $counted = 0;

        foreach ($cells as $cell) {
            if (!$cell->counts($context->emptyAsZero)) {
                $status[$cell->itemKey] = $cell->excluded ? GradeCell::STATUS_DROPPED : GradeCell::STATUS_NOVALUE;

                continue;
            }

            $status[$cell->itemKey] = GradeCell::STATUS_USED;
            $weights[$cell->itemKey] = $cell->weightBp;
            $earned += $cell->effectiveGrade() - $cell->rawGradeMin;
            $counted++;

            if (!\in_array($cell->itemKey, $bonus, true)) {
                $maximum += $cell->rawGradeMax - $cell->rawGradeMin;
            }
        }

        if ($counted === 0) {
            return CategoryTotal::nothingCounted($context->categoryMax ?? 0.0, $status);
        }

        $range = $context->categoryMax ?? $maximum;

        // Capped, and capped against the range the total is reported out of
        // rather than against the sum of the non-bonus maxima, so a category
        // with its own declared maximum caps where its author said it does.
        $value = $context->categoryMin + min($earned, max($range - $context->categoryMin, 0.0));

        return CategoryTotal::of($value, $range, $status, $weights);
    }

    /**
     * @return list<string>
     */
    private function bonusKeys(GradeAggregationContext $context): array
    {
        $declared = $context->parameters[self::PARAM_ITEMS] ?? [];

        if (!\is_array($declared)) {
            return [];
        }

        $keys = [];

        foreach ($declared as $key) {
            if (\is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
