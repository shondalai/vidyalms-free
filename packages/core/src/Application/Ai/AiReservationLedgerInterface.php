<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\CostEstimate;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;

/**
 * Headroom taken before a call and given back or spent after it.
 *
 * A ceiling that reads only the settled figure does not bind under fan-out: a
 * course build is dozens of calls issued from a binned outbox with two
 * runners over one pending row as the normal case, so every concurrent unit
 * would sum the same unsettled total and every one of them would pass. The
 * reservation is what the windowed sum reads as `GREATEST(cost_charged,
 * cost_reserved)`, and it is why a run that never reaches a terminal state
 * has its reservation released by a sweep rather than left holding headroom
 * for ever.
 */
interface AiReservationLedgerInterface
{
    /** Hold headroom for one call, keyed on a reference the caller mints. */
    public function reserve(string $callRef, CostEstimate $estimate): void;

    /** Convert the reservation into what was actually spent. */
    public function settle(string $callRef, CostRecord $actual): void;

    /** Give the headroom back when the call produced nothing. */
    public function release(string $callRef): void;
}
