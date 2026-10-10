<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AutomationFanoutTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AutomationStepTask;

/**
 * The two automation queue hops, expressed as scheduler rows the moment the
 * row that needs them exists.
 *
 * This is NotificationJobs for the automation engine, and it exists for the
 * same measured reason. Both handlers declare RunsPeriodically, so the host's
 * recurring clock has always been able to find a waiting row; what it could
 * not do is find it promptly. On an hourly host clock the step task ran once
 * per hour at a chunk of 25 and the fan-out four bins at 50, so a cohort rule
 * over five thousand subjects needed two hundred hours, and the two per-row
 * dedupe key functions the design published had no caller anywhere: each host
 * would have invented its own use for them, which is exactly the failure
 * KernelReady exists to prevent.
 *
 * So the row that causes work also queues the work, and the periodic pair
 * stays registered as the safety net, because a process that dies between the
 * commit and the schedule must not strand the row and because the sweep writes
 * intent rows by another road.
 *
 * **Both keys are the ones the design publishes.** The fan-out key names the
 * run and the bin; the step key is the step's own idempotency digest, so the
 * job, the queue row and the ledger claim all name one effect and a host
 * runner that enqueues the same step twice replaces the pending job rather
 * than running the effect twice.
 */
final class AutomationJobs
{
    /** The one bin an immediate wake-up asks for. */
    private const IMMEDIATE_BIN = 0;

    public function __construct(
        private readonly SchedulerPort $scheduler,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * Wakes the fan-out for one intent row that has just committed.
     */
    public function queueFanout(int $runId): void
    {
        if ($runId < 1) {
            return;
        }

        $this->scheduler->schedule(new ScheduledTask(
            AutomationFanoutTask::TYPE,
            [
                'runId' => $runId,
                'binIndex' => self::IMMEDIATE_BIN,
                'binCount' => 1,
                'limit' => RunAutomationFanout::DEFAULT_LIMIT,
            ],
            $this->clock->now(),
            AutomationFanoutTask::dedupeKeyFor($runId, self::IMMEDIATE_BIN),
        ));
    }

    /**
     * Wakes the drain for one queued step.
     *
     * The run instant is the row's own, so a step held behind a delay anchor
     * does not put a job on the queue that can only decline to run. The drain
     * releases due rows at the head of every turn, so a host that fires the
     * job early costs one query.
     */
    public function queueStep(string $idempotencyDigest, \DateTimeImmutable $runAt): void
    {
        if ($idempotencyDigest === '') {
            return;
        }

        $this->scheduler->schedule(new ScheduledTask(
            AutomationStepTask::TYPE,
            [
                'chunk' => AutomationStepTask::DEFAULT_CHUNK,
            ],
            $runAt,
            AutomationStepTask::dedupeKeyFor($idempotencyDigest),
        ));
    }
}
