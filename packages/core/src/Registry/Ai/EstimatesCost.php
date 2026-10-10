<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;

/**
 * A task that can say what it is about to cost before it runs.
 *
 * **Absent means** the ceiling check uses the route's declared per-run
 * maximum, never an optimistic guess. An unknown estimate is not zero.
 *
 * The bridge namesake under Registry\Ai\Bridge answers the same question one
 * layer down, about one call rather than one run, and the two are deliberately
 * separate: a task knows how many calls it intends to make and a bridge knows
 * what one of them costs.
 */
interface EstimatesCost
{
    public function estimate(AiTaskRequest $request, AiTaskContext $context): CostEstimate;
}
