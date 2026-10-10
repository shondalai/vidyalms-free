<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation\SqlAutomationRuleRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Context\SweepContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerRegistry;

/**
 * Every registered sweep trigger, run for every armed automation rule on it.
 *
 * The trigger split is reused unchanged, because no event exists for the
 * absence of an event: an event trigger has a domain event behind it and a
 * sweep trigger has only a query and a clock. Adding a second rule language
 * would be the duplication the design refuses when it declines to build a
 * DripService.
 *
 * **Sweeps carry a cursor and a configured, observable chunk size.** Adapting
 * the chunk silently to server capacity is a deliberate departure from the
 * products that do: an operator cannot reason about a number the product will
 * not show them. The cursor here is the bin, which is the shipped sweep
 * contract's own shard, plus the per-turn candidate bound below.
 */
final class RunAutomationSweep
{
    /** The chunk size an operator sees and can change, per sweep trigger. */
    public const DEFAULT_CANDIDATES_PER_TRIGGER = 200;

    public function __construct(
        private readonly NotificationTriggerRegistry $triggers,
        private readonly SqlAutomationRuleRepository $rules,
        private readonly OpenAutomationRuns $open,
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly string $siteTimezone = 'UTC',
    ) {
    }

    /** How many intent rows this turn wrote. */
    public function run(
        int $binIndex = 0,
        int $binCount = 1,
        string $periodBucket = '',
        int $candidatesPerTrigger = self::DEFAULT_CANDIDATES_PER_TRIGGER,
    ): int {
        $bucket = $periodBucket === '' ? $this->clock->now()->format('Y-m-d\TH') : $periodBucket;
        $opened = 0;

        foreach ($this->triggers->sweeps() as $sweep) {
            $rules = $this->rules->enabledForTrigger($sweep->key());

            if ($rules === []) {
                continue;
            }

            foreach ($rules as $rule) {
                $context = SweepContext::create(
                    $this->clock->now(),
                    $this->database,
                    [],
                    $binIndex,
                    max(1, $binCount),
                    $bucket,
                    $rule->id ?? 0,
                    $this->siteTimezone,
                );

                $seen = 0;

                foreach ($sweep->dueCandidates($context) as $occurrence) {
                    if ($seen >= $candidatesPerTrigger) {
                        // The bound is observable and reported by the task's
                        // own summary line rather than silently absorbed.
                        break;
                    }

                    ++$seen;

                    if ($this->open->forRule($rule, $sweep->key(), $occurrence) !== null) {
                        ++$opened;
                    }
                }
            }
        }

        return $opened;
    }
}
