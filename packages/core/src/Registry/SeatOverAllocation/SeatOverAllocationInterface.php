<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\SeatOverAllocation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatDecisionContext;
use Shondalai\VidyaLMS\Core\Domain\Organisation\SeatOverAllocationOutcome;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * What happens when an assignment would exceed the funded count.
 *
 * The free stance refuses and queues an inspectable pending row, so a bulk
 * enrolment of five hundred into four hundred and eighty leaves twenty
 * people visible rather than discarded.
 *
 * Miss direction: fail closed: the assignment is refused and recorded as a pending row.
 *
 * The context carries the availability the caller already computed under the
 * organisation lock, so a provider never recomputes it and two providers
 * cannot disagree about how many seats are left.
 */
interface SeatOverAllocationInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * The stance, for one assignment the pool cannot currently seat.
     *
     * Called only when availability is short: a pool with room seats the
     * person without asking anybody, which is what keeps the common path free
     * of a registry lookup that can only answer one way.
     */
    public function decide(SeatDecisionContext $context): SeatOverAllocationOutcome;
}
