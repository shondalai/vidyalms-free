<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

use Shondalai\VidyaLMS\Core\Registry\Context\BridgeContext;

/**
 * A bridge that can be asked what its counterparty actually billed.
 *
 * **Absent means** no counterparty ledger; the local record is authoritative
 * and the usage report says so.
 *
 * This capability exists because the reconciliation sweep is a free scheduled
 * task and packages/core may not name a vendor. Without a capability to
 * resolve through the registry, the sweep could only be implemented by a core
 * file naming one, which CI forbids, or not at all, which would remove the
 * only defence against a billing dispute. With it, the sweep resolves bridges
 * from the registry, calls only those declaring this, and does nothing at all
 * on a free site.
 */
interface ReconcilesUsage
{
    /**
     * @param list<string> $providerRequestIds
     *
     * @return list<UsageRecord>
     */
    public function usageFor(array $providerRequestIds, BridgeContext $context): array;
}
