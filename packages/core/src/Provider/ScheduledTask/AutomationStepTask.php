<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Automation\RunAutomationStep;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Executes queued steps.
 *
 * **Its dedupe key is the step's own idempotency digest**, which is the design's
 * own answer and not a convenience: the queue row, the ledger claim and the
 * scheduled job then all name one effect, so a host runner that enqueues the
 * same step twice replaces the pending job rather than running the effect
 * twice.
 *
 * **It owns its own retry and therefore does not throw.** The ladder lives on
 * the step's row, with its attempt counter and its next attempt instant, and
 * the handler records the outcome and returns a summary line so the runner has
 * nothing to retry. Two backoff regimes over one unit of work compose
 * differently under each host's runner: the runner's retries defeat the
 * ladder's attempt cap and its jitter curve never actually runs.
 */
final class AutomationStepTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const TYPE = 'vidyalms.automation.step';

    /** One minute, so a step whose moment has come is not held for a quarter of an hour. */
    public const PERIOD_SECONDS = 60;

    /** The observable chunk an operator can reason about. */
    public const DEFAULT_CHUNK = 25;

    public function __construct(private readonly RunAutomationStep $steps)
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
        return (string) intdiv($now->getTimestamp(), self::PERIOD_SECONDS);
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return 'automation.step.periodic:' . $periodBucket . ':' . $binIndex;
    }

    /**
     * The per-step key: the step's own idempotency digest, so the job, the
     * queue row and the ledger claim all name one effect.
     */
    public static function dedupeKeyFor(string $idempotencyDigest): string
    {
        return self::TYPE . ':' . $idempotencyDigest;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $chunk = max(1, $run->int('chunk', self::DEFAULT_CHUNK));
        $done = $this->steps->drain($chunk);

        return sprintf('Automation steps: %d executed at chunk %d.', $done, $chunk);
    }
}
