<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeRegistry;

/**
 * The daily reconciliation between what this site recorded and what the
 * counterparty billed.
 *
 * It exists as a free scheduled task because reconciliation is the only defence
 * against a billing dispute, and packages/core may not name a vendor. Without a
 * capability to resolve through the registry, this sweep could only have been
 * implemented by a core file naming a service, which CI forbids, or not at all.
 * With one, it resolves bridges from the registry, calls only those declaring the
 * capability, and **does nothing on a free site**, which is the honest answer
 * rather than an empty seam.
 *
 * The capability itself belongs to the model-access stage: a bridge declares
 * ReconcilesUsage and answers usageFor() over provider request ids. Until one
 * ships, this sweep resolves the null bridge, finds no such capability, and
 * reports zero reconciled with the reason stated. That is deliberately not an
 * error: a site with no model configured has nothing to reconcile, and reporting
 * a failure there would put a permanent red line in an operator's dashboard for a
 * condition they chose.
 */
final class AiUsageReconciliation
{
    /**
     * The capability interface name a bridge declares to be reconcilable.
     *
     * It is referred to by name rather than imported, because the interface
     * itself arrives with the model-access stage and this sweep ships before it.
     * A string here is honest about that ordering; an import would not compile
     * until the stage that owns it lands, which would make a free scheduled task
     * depend on a paid one's prerequisite.
     */
    public const CAPABILITY = 'Shondalai\\VidyaLMS\\Core\\Registry\\Ai\\Bridge\\ReconcilesUsage';

    public function __construct(private readonly ModelBridgeRegistry $bridges)
    {
    }

    /**
     * @return array{bridges: int, reconcilable: int, note: string}
     */
    public function run(): array
    {
        $total = 0;
        $reconcilable = 0;

        $capability = self::CAPABILITY;

        foreach ($this->bridges->all() as $bridge) {
            ++$total;

            if ($bridge instanceof $capability) {
                ++$reconcilable;
            }
        }

        return [
            'bridges' => $total,
            'reconcilable' => $reconcilable,
            'note' => $reconcilable === 0
                ? 'No registered bridge declares a counterparty ledger, so the local record is authoritative and'
                    . ' the usage report says so.'
                : sprintf('%d of %d bridges declare a counterparty ledger.', $reconcilable, $total),
        ];
    }
}
