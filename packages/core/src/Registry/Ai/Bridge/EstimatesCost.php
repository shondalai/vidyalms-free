<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

use Shondalai\VidyaLMS\Core\Registry\Ai\BridgeRequest;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostEstimate;

/**
 * A bridge that can price one call before making it.
 *
 * **Absent means** the ceiling check uses the route's declared per-run
 * maximum. That is the pessimistic answer on purpose: an optimistic guess
 * against a ceiling is a ceiling that passes everything.
 *
 * The task namesake under Registry\Ai answers the same question one layer up,
 * about a whole run rather than one call.
 */
interface EstimatesCost
{
    public function estimate(BridgeRequest $request): CostEstimate;
}
