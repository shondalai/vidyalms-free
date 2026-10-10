<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Automation\RunAutomationSweep;
use Shondalai\VidyaLMS\Core\Registry\Context\SweepContext;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Runs every registered sweep trigger on the Automation surface.
 *
 * The first of the automation engine's three task types, and the only one the
 * clock starts. A sweep has no moment of its own: nothing happens when a
 * learner fails to log in, when a window opens or when access is about to
 * expire, so something has to notice, and a sweep nothing starts is the empty
 * seam the free-provider rule forbids.
 *
 * **It owns its own retry and therefore does not throw.** Two backoff regimes
 * over one unit of work compose differently under each host's runner, and the
 * runner's retries would then defeat the step machine's attempt cap and its
 * jitter curve would never actually run. The retry that matters is the step's,
 * on its own row.
 */
final class AutomationSweepTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const TYPE = 'vidyalms.automation.sweep';

    public function __construct(private readonly RunAutomationSweep $sweep)
    {
    }

    public function key(): string
    {
        return self::TYPE;
    }

    public function binCount(): int
    {
        return SweepContext::DEFAULT_BIN_COUNT;
    }

    /**
     * One bucket per hour, matching the notification sweep, because both hosts
     * fire the recurring clock opportunistically rather than punctually and a
     * bucket is what makes a clock that fires twice inside one period open one
     * set of runs rather than two.
     */
    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d\TH');
    }

    /**
     * Namespaced by the task type, because the notification sweep's periodic
     * key is the same shape over the same buckets and the two must not replace
     * each other's pending rows.
     */
    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return sprintf('%s:%s:%d', self::TYPE, $periodBucket, $binIndex);
    }

    public function run(ScheduledTaskRun $run): string
    {
        $binIndex = max(0, $run->int('binIndex', 0));
        $binCount = max(1, $run->int('binCount', SweepContext::DEFAULT_BIN_COUNT));
        $chunk = max(1, $run->int('chunk', RunAutomationSweep::DEFAULT_CANDIDATES_PER_TRIGGER));

        $opened = $this->sweep->run(
            $binIndex,
            $binCount,
            $run->string('periodBucket', $this->periodBucket(new \DateTimeImmutable())),
            $chunk,
        );

        // The chunk is in the summary line because an operator cannot reason
        // about a number the product will not show them, which is the named
        // departure from products that adapt it silently.
        return sprintf(
            'Automation sweep %d of %d opened %d run(s) at chunk %d.',
            $binIndex,
            $binCount,
            $opened,
            $chunk,
        );
    }
}
