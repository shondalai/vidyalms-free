<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Ai\AiUsageReconciliation;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * The daily reconciliation against a counterparty ledger, naming no vendor.
 *
 * It resolves every registered bridge, calls only those declaring the
 * reconciliation capability, and does nothing on a free site. That is why it is a
 * free scheduled task rather than a paid one: reconciliation is the only defence
 * against a billing dispute, packages/core may not name a service, and without a
 * capability resolved through the registry this could only have been a core file
 * naming a vendor, which CI forbids, or nothing at all.
 *
 * A free site reporting "no counterparty ledger" is the honest answer and not a
 * failure. Reporting a failure there would put a permanent red line in an
 * operator's dashboard for a condition they chose.
 */
final class AiReconcileTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const TYPE = 'vidyalms.ai.reconcile';

    public function __construct(private readonly AiUsageReconciliation $reconciliation)
    {
    }

    public function key(): string
    {
        return self::TYPE;
    }

    public function binCount(): int
    {
        return 1;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return sprintf('%s:%s:%d', self::TYPE, $periodBucket, $binIndex);
    }

    public function run(ScheduledTaskRun $run): string
    {
        unset($run);

        $result = $this->reconciliation->run();

        return sprintf('AI reconcile: %s', $result['note']);
    }
}
