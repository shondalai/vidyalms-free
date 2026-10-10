<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Ai\AiStaleDraftSweep;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * The eighth AI task, and the one whose absence was a gap rather than an
 * omission.
 *
 * A draft idle beyond the configured window moves to discarded with the reject
 * reason code `stale_unreviewed`, so an unreviewed draft is DECIDED ABOUT rather
 * than aged out. The retention half is closed by retain_until being written at
 * insert; this closes the review half, so the queue empties honestly and the
 * reason corpus can count how often nobody looked.
 */
final class AiStaleDraftsTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const TYPE = 'vidyalms.ai.stale_drafts';

    public function __construct(private readonly AiStaleDraftSweep $sweep)
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
        $result = $this->sweep->run($run->int('batch', AiStaleDraftSweep::DEFAULT_BATCH));

        return sprintf(
            'AI stale drafts: %d of %d examined draft(s) discarded as %s.',
            $result['discarded'],
            $result['examined'],
            AiStaleDraftSweep::REASON_CODE,
        );
    }
}
