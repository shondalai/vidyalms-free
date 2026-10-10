<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\NotificationFanoutTask;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\NotificationSendTask;

/**
 * The two queue hops, expressed as scheduler rows the moment the row that
 * needs them exists.
 *
 * Both handlers declare RunsPeriodically, so the host's recurring clock has
 * always been able to find a waiting row. What it could not do is find it
 * promptly: an intent row written at 09:00:01 waited for the next turn of a
 * five minute clock, and the recipient row the fan-out then wrote waited for
 * the turn after that, so a password-shaped transactional mail took up to ten
 * minutes to leave on a site whose clock was punctual and longer on one whose
 * clock is opportunistic. Worse, on a host whose recurring clock was never
 * provisioned nothing moved at all, which is exactly what both hosts did
 * until the rows were inserted by hand.
 *
 * So the row that causes work also queues the work. The periodic pair stays
 * registered as the safety net, because a process that dies between the
 * commit and the schedule must not strand the row, and because the sweeps
 * write intent rows by another road.
 *
 * **The dedupe key names the row that caused the wake-up, and the handler
 * still drains its bin.** The design fixes the keys as `fanout:{queueRowId}:
 * {bin}` and the queue row's own dedupe digest, which is what makes two
 * writers of the same row collapse to one job. It does not make the job
 * single-row: the handlers claim what is pending, so a second wake-up whose
 * row a previous run already took finds nothing and costs one query, which is
 * the cheap direction. The alternative, a job that may only touch the row it
 * names, loses every row whose wake-up was lost.
 */
final class NotificationJobs
{
    /** The one bin an immediate wake-up asks for. */
    private const IMMEDIATE_BIN = 0;

    public function __construct(
        private readonly SchedulerPort $scheduler,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * `fanout:{queueRowId}:{bin}`, from the design's table of task types.
     */
    public static function fanoutDedupeKey(int $intentId, int $bin = self::IMMEDIATE_BIN): string
    {
        return 'fanout:' . $intentId . ':' . $bin;
    }

    /**
     * Wakes the fan-out for one intent row that has just committed.
     */
    public function queueFanout(int $intentId): void
    {
        if ($intentId < 1) {
            return;
        }

        $this->scheduler->schedule(new ScheduledTask(
            NotificationFanoutTask::TYPE,
            [
                'intentId' => $intentId,
                'binIndex' => self::IMMEDIATE_BIN,
                'binCount' => 1,
                'limit' => NotificationFanout::DEFAULT_LIMIT,
            ],
            $this->clock->now(),
            self::fanoutDedupeKey($intentId),
        ));
    }

    /**
     * Wakes the drain for one recipient row.
     *
     * The key is the row's own dedupe digest rather than anything derived
     * here, because the digest is already the identity two writers of the
     * same delivery agree on; a second key over the same row would let a
     * duplicate wake-up survive the deduplication the digest exists for.
     *
     * The run instant is the row's, so a row deferred by a schedule, by quiet
     * hours or by a backoff does not put a job on the queue that can only
     * decline to send. The drain releases due rows at the head of every run,
     * so a host that fires the job early costs one query.
     */
    public function queueSend(int $queueId, string $dedupeKey, \DateTimeImmutable $runAt): void
    {
        if ($queueId < 1 || $dedupeKey === '') {
            return;
        }

        $this->scheduler->schedule(new ScheduledTask(
            NotificationSendTask::TYPE,
            [
                'queueId' => $queueId,
                'limit' => NotificationSender::DEFAULT_LIMIT,
            ],
            $runAt,
            $dedupeKey,
        ));
    }
}
