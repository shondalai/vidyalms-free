<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;

/**
 * One line the counterparty's own ledger holds about a call this site made.
 *
 * It is keyed on the provider request id rather than on the run, because the
 * reconciliation sweep asks the far side what it billed and then matches: a
 * record keyed on our own identifier could only ever confirm what we already
 * believed.
 */
final class UsageRecord
{
    private function __construct(
        public readonly string $providerRequestId,
        public readonly CostRecord $cost,
        public readonly ?int $tokensIn,
        public readonly ?int $tokensOut,
        public readonly ?\DateTimeImmutable $billedAt,
    ) {
    }

    public static function of(
        string $providerRequestId,
        CostRecord $cost,
        ?int $tokensIn = null,
        ?int $tokensOut = null,
        ?\DateTimeImmutable $billedAt = null,
    ): self {
        return new self($providerRequestId, $cost, $tokensIn, $tokensOut, $billedAt);
    }
}
