<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\GradeAggregation;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * How a grade category reduces its cells to one total.
 *
 * **It is not the quiz aggregator.**
 * {@see \Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationMethodInterface}
 * folds a learner's counted attempts into the one grade a quiz reports; its
 * input is an `AttemptScore` and its output an `AggregatedGrade`, neither of
 * which can carry a snapshotted maximum, a weight in basis points, an
 * exclusion, a visibility flag or a per-cell aggregation status. Registering
 * `natural` into it would also list these providers in the quiz settings
 * picker, which enumerates that registry. Two seams, and saying so now is
 * cheaper than discovering it later.
 *
 * Free ships `natural` (points sum, where the category maximum is the sum of
 * the item maxima) and `mean`; Pro adds weighted mean, simple weighted mean,
 * drop lowest N and extra credit.
 *
 * **Miss direction: closed and visible.** The category total is not computed,
 * the cells carry `unknown`, and the screen names the missing provider. A
 * guessed total is a grade dispute; an absent total is a support ticket.
 */
interface GradeAggregationInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The provider's own stored parameters, as JSON Schema 2020-12.
     *
     * @return array<string, mixed>
     */
    public function paramsSchema(): array;

    /**
     * The shape version this provider writes today, so a stored parameter set
     * has an upgrader chain behind it rather than a number nothing reads.
     */
    public function currentShape(): int;

    /** @param list<GradeCell> $cells */
    public function aggregate(array $cells, GradeAggregationContext $context): CategoryTotal;
}
