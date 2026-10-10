<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Automation\RunAutomationFanout;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Expands intent rows into queue rows, one bin at a time.
 *
 * **It is started by the clock, and it has to be.** An intent row is written
 * by the use case that changed the state and nothing else ever queues this
 * handler, so a build where it did not declare RunsPeriodically would leave
 * every intent row ever written sitting in pending_fanout for the life of the
 * site: the machinery works perfectly and nothing starts it. That is exactly
 * the failure the notification fan-out already had once, and this is the same
 * fix rather than a second discovery of it.
 *
 * The per-run dedupe key the design publishes,
 * vidyalms.automation.fanout:{runId}:{bin}, is what a caller waking a single
 * run uses; the periodic key below is namespaced apart from it because both
 * halves are integers and on a busy site those numbers meet.
 */
final class AutomationFanoutTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const TYPE = 'vidyalms.automation.fanout';

    /** Five minutes, as the bucket width in seconds. */
    public const PERIOD_SECONDS = 300;

    /** Four bins, matching the notification fan-out for the same reason. */
    public const BINS = 4;

    public function __construct(private readonly RunAutomationFanout $fanout)
    {
    }

    public function key(): string
    {
        return self::TYPE;
    }

    public function binCount(): int
    {
        return self::BINS;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return (string) intdiv($now->getTimestamp(), self::PERIOD_SECONDS);
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return 'automation.fanout.periodic:' . $periodBucket . ':' . $binIndex;
    }

    /** The per-run wake-up key the design names, for a caller waking one run. */
    public static function dedupeKeyFor(int $runId, int $binIndex): string
    {
        return sprintf('%s:%d:%d', self::TYPE, $runId, $binIndex);
    }

    public function run(ScheduledTaskRun $run): string
    {
        $limit = max(1, $run->int('limit', RunAutomationFanout::DEFAULT_LIMIT));
        $binIndex = max(0, $run->int('binIndex', 0));
        $binCount = max(1, $run->int('binCount', 1));

        $written = $this->fanout->run($limit, $binIndex, $binCount);

        return sprintf('Automation fan-out %d of %d wrote %d step(s).', $binIndex, $binCount, $written);
    }
}
