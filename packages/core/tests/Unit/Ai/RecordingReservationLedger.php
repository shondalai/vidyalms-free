<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Ai;

use Shondalai\VidyaLMS\Core\Application\Ai\AiReservationLedgerInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostEstimate;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;

/**
 * A reservation ledger that keeps the estimate it was handed.
 *
 * The shipped in-memory ledger answers which references are outstanding and
 * what settled, which is what the ordering cases need, and deliberately does
 * not publish the held amount. This one does, because the amount is the whole
 * question when a bridge cannot estimate: the windowed ceiling reads
 * `GREATEST(cost_charged, cost_reserved)`, so a run that reserved an unknown
 * held nothing against the month it spent in.
 */
final class RecordingReservationLedger implements AiReservationLedgerInterface
{
    /** @var array<string, CostEstimate> */
    public array $reserved = [];

    /** @var array<string, CostRecord> */
    public array $settled = [];

    /** @var list<string> */
    public array $released = [];

    public function reserve(string $callRef, CostEstimate $estimate): void
    {
        $this->reserved[$callRef] = $estimate;
    }

    public function settle(string $callRef, CostRecord $actual): void
    {
        $this->settled[$callRef] = $actual;
    }

    public function release(string $callRef): void
    {
        $this->released[] = $callRef;
    }

    /** The one estimate held, for a case that makes exactly one call. */
    public function onlyReservation(): ?CostEstimate
    {
        return array_values($this->reserved)[0] ?? null;
    }
}
