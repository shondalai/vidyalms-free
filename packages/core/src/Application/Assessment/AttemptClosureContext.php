<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Organisation\AttemptDisposition;

/**
 * The authorisation an already-authorised attempt closure carries.
 *
 * {@see SubmitAttempt::executeForAccessClosure()} is the one entry point in
 * the assessment area that closes an attempt without a Principal, and a
 * docblock is not a guard. So it takes this, and this has a private
 * constructor and one named factory: a caller who has not been through the
 * seat revocation ceremony cannot build one, and a caller who has is holding
 * the ledger row id that justifies it.
 *
 * The seat event id is the drill-through the design asks for, from the
 * attempt back to the ledger row that caused it. It is null on exactly one
 * path and the factory says which: an organisation grant that spends no seat
 * writes no seat event, so there is no row to point at and inventing one
 * would put a number in the ledger that no balance depends on.
 */
final class AttemptClosureContext
{
    private function __construct(
        public readonly int $attemptId,
        public readonly string $disposition,
        public readonly string $reasonKey,
        public readonly ?int $seatEventId,
    ) {
    }

    /**
     * The closure as an organisation access termination poses it.
     *
     * The same ceremony for the grants that never had a seat behind them: a
     * direct organisation grant, a course withdrawn from an organisation, a
     * membership closed. They strand an in-flight attempt exactly as a seat
     * reclaim does, and the guard on the assessment path refuses them
     * identically, so leaving them out would have closed the invariant for
     * the smaller half of the revocations.
     */
    public static function forAccessTermination(int $attemptId, string $disposition, string $reasonKey): self
    {
        if ($attemptId < 1) {
            throw new \InvalidArgumentException('An attempt closure names a real attempt.');
        }

        return new self($attemptId, AttemptDisposition::of($disposition), $reasonKey, null);
    }

    /**
     * The closure as the seat revocation ceremony poses it.
     *
     * The disposition is validated here rather than trusted, because the
     * value travels from an HTTP request through three use cases and a
     * misspelt one silently grading an attempt somebody meant to void is not
     * recoverable.
     */
    public static function forSeatRevocation(
        int $attemptId,
        string $disposition,
        string $reasonKey,
        int $seatEventId,
    ): self {
        if ($attemptId < 1 || $seatEventId < 1) {
            throw new \InvalidArgumentException('An attempt closure names a real attempt and the seat event that caused it.');
        }

        return new self($attemptId, AttemptDisposition::of($disposition), $reasonKey, $seatEventId);
    }
}
