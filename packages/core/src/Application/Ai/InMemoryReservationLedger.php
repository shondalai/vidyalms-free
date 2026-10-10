<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\CostEstimate;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;

/**
 * The reservation ledger a free site actually runs.
 *
 * On a build with no model access every call is free by construction, so
 * there is nothing to hold headroom against and nothing to settle. Keeping
 * the reservations in memory rather than discarding them means the free path
 * still exercises the reserve, settle and release ordering the paid path
 * depends on, and a test can assert that a refusal released rather than
 * settled.
 */
final class InMemoryReservationLedger implements AiReservationLedgerInterface
{
    /** @var array<string, CostEstimate> */
    private array $held = [];

    /** @var array<string, CostRecord> */
    private array $settled = [];

    /** @var list<string> */
    private array $released = [];

    public function reserve(string $callRef, CostEstimate $estimate): void
    {
        $this->held[$callRef] = $estimate;
    }

    public function settle(string $callRef, CostRecord $actual): void
    {
        unset($this->held[$callRef]);
        $this->settled[$callRef] = $actual;
    }

    public function release(string $callRef): void
    {
        unset($this->held[$callRef]);
        $this->released[] = $callRef;
    }

    /** @return list<string> the references still holding headroom */
    public function outstanding(): array
    {
        return array_keys($this->held);
    }

    /** @return array<string, CostRecord> */
    public function settlements(): array
    {
        return $this->settled;
    }

    /** @return list<string> */
    public function releases(): array
    {
        return $this->released;
    }
}
