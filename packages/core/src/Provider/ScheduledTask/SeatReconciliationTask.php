<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationSweepAuthority;
use Shondalai\VidyaLMS\Core\Application\Organisation\SeatThresholdAlerts;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\SweptRecordStore;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Recomputes both derivations of every pool's availability and alerts when
 * they disagree.
 *
 * **Two independent derivations that must agree is what turns a counter into
 * an auditable balance.** One is `seats_purchased + seats_granted` minus the
 * number of assignments in a seat-holding state; the other is the signed sum
 * of the ledger. Either alone is a number somebody has to trust. Together
 * they are a property, and this is the task that checks it.
 *
 * **It alerts and never silently corrects.** A silent correction is how a
 * defect becomes invisible: the number is right again on the screen, the
 * cause is still there, and the next divergence looks like the first one.
 * What it does write is `seat_pools.seats_assigned`, which is documented as a
 * reconciled cache no report reads, and which is derived rather than
 * accumulated, so writing it cannot itself introduce a drift.
 *
 * The alert rides the seat threshold triggers rather than a fourth one. An
 * administrator does not need one mail saying the pool is nearly full and a
 * second saying the number in the first cannot be trusted; the occurrence
 * carries `reconciled`, and a diverged pool says so in the message it was
 * going to send anyway.
 *
 * The walk is a resumable cursor rather than a full pass. A site with four
 * hundred pools reconciles a hundred a run and wraps, so one run is bounded
 * and every pool is still reached; the cursor is a settings row because it is
 * exactly the kind of site-wide operational value no provider interprets.
 */
final class SeatReconciliationTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const KEY = 'vidyalms.organisation.seat_reconciliation';

    /** Where the resumable walk left off. */
    public const CURSOR_SETTING = 'organisation.seat_reconciliation_cursor';

    /** How many pools one run inspects. */
    public const BATCH = 100;

    public function __construct(
        private readonly SweptRecordStore $records,
        private readonly OrganisationSweepAuthority $authority,
        private readonly OrganisationLearningStore $store,
        private readonly SeatThresholdAlerts $alerts,
        private readonly Settings $settings,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $cursor = max(0, $this->settings->getIntish(self::CURSOR_SETTING, 0));
        $rows = $this->records->poolsToReconcile(self::BATCH, $cursor);
        $checked = 0;
        $diverged = 0;
        $skipped = 0;
        $last = $cursor;

        foreach ($rows as $row) {
            $poolId = (int) $row['id'];
            $last = $poolId;
            $scope = $this->authority->forPool($poolId);

            if ($scope === null) {
                ++$skipped;

                continue;
            }

            $pool = $this->store->pool($scope, $poolId);
            $balance = $this->store->balance($scope, $poolId);
            ++$checked;

            if (!$balance['reconciled']) {
                ++$diverged;
            } elseif ((int) $pool['seats_assigned'] !== $balance['held']) {
                // The cache, and only the cache. It is derived from the count
                // this run just took rather than adjusted by a difference, so
                // repairing it cannot invent a balance of its own.
                $this->store->update('seat_pools', $scope, $poolId, ['seats_assigned' => $balance['held']]);
            }

            $this->alerts->summary($scope, $pool, $balance, $run->string('period_bucket', $this->today()));
        }

        // Wrapping rather than stopping. A run that returned a short page has
        // reached the end of the table, and the next run starts again at the
        // beginning, so a pool added while the walk was halfway through is
        // still reached within two periods.
        $this->settings->set(self::CURSOR_SETTING, \count($rows) < self::BATCH ? 0 : $last);

        return sprintf('checked=%d diverged=%d skipped=%d cursor=%d', $checked, $diverged, $skipped, $last);
    }

    private function today(): string
    {
        return $this->records->now();
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
        return self::KEY . ':' . $periodBucket;
    }
}
