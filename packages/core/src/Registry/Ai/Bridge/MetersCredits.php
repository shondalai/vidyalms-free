<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

use Shondalai\VidyaLMS\Core\Registry\Ai\CostEstimate;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;

/**
 * A bridge with a counterparty ledger it can reserve against and settle to.
 *
 * **Absent means** no external ledger; the run records its own estimate,
 * settles against nothing, and the usage report says the spend is unmetered.
 *
 * The amounts here are denominated in the unit the bridge declares, which is
 * one of the four CostRecord names and never a metering service's own product
 * word. The interface is named for the shape a metered route has rather than
 * for any one service, and the vocabulary rule that matters is the one on the
 * unit: a bridge whose upstream denominates in its own product unit answers
 * `provider_units`.
 */
interface MetersCredits
{
    /** What is left, in this bridge's declared unit, or unknown. */
    public function balance(): CostRecord;

    /**
     * Hold headroom for one call and return the reservation reference.
     *
     * A ceiling that reads only settled figures does not bind under fan-out,
     * which is why the reservation exists at all.
     */
    public function reserve(CostEstimate $estimate): string;

    /** Convert a reservation into a real charge. */
    public function settle(string $reservationRef, CostRecord $actual): void;

    /** Give the headroom back when the call never happened. */
    public function release(string $reservationRef): void;
}
