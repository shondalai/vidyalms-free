<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Ai\AiRetentionSweep;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * The daily purge, per artefact class, and the release of stuck reservations.
 *
 * It purges past retain_until, cascading to attempts, drafts, findings and run
 * subjects, and **leaves ai_artefact_provenance untouched**: the
 * editorial-responsibility record has to outlive the prompt log that produced it,
 * and the mechanism is that the provenance repository has no purge method at all
 * rather than a condition inside this loop.
 *
 * It also releases the reservation of any run left non-terminal past its
 * deadline, because a reservation that never settles holds ceiling headroom for
 * ever and would eventually read as a site permanently over budget.
 *
 * Daily, and bucketed by day, because a periodic clock both hosts fire
 * opportunistically rather than punctually needs a bucket or it opens two sweeps
 * inside one period.
 */
final class AiRetentionTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const TYPE = 'vidyalms.ai.retain';

    public function __construct(private readonly AiRetentionSweep $sweep)
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
        $result = $this->sweep->run($run->int('batch', AiRetentionSweep::DEFAULT_BATCH));

        return sprintf(
            'AI retention purged %d run(s) and %d draft(s) and released %d reservation(s). Provenance rows are'
                . ' never purged.',
            $result['runs_purged'],
            $result['drafts_purged'],
            $result['reservations_released'],
        );
    }
}
