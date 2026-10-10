<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

/**
 * A ladder, a cap and whether to jitter, as one object.
 *
 * It is a value object rather than three methods on the policy interface for
 * the reason every seam in this wave is closed the same way: the interface is
 * shipped bytes a third party implements, and a fourth field on a plan is
 * additive here while a fourth method there is forbidden. A policy that later
 * wants to say something about a per-attempt timeout says it on the plan.
 *
 * The free default is the published Svix ladder, immediate then 5s, 5m, 30m,
 * 2h, 5h, 10h, 10h, whose cumulative attempts land past two, seven, seventeen
 * and twenty-seven hours. That total is why the automation scope's outcome
 * TTL has to outlive it: an attempt arriving after the outcome expired runs
 * the effect a second time.
 */
final class RetryPlan
{
    /** @var list<int> */
    public readonly array $ladderSeconds;

    /**
     * @param list<int> $ladderSeconds the wait before each attempt after the first
     */
    private function __construct(
        array $ladderSeconds,
        public readonly int $maxAttempts,
        public readonly bool $jitter,
    ) {
        $this->ladderSeconds = $ladderSeconds;
    }

    /**
     * @param list<int> $ladderSeconds
     */
    public static function of(array $ladderSeconds, int $maxAttempts, bool $jitter = true): self
    {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('A retry plan allows at least the first attempt.');
        }

        return new self($ladderSeconds, $maxAttempts, $jitter);
    }

    /** One attempt and no ladder, which is a real answer rather than an absence. */
    public static function once(): self
    {
        return new self([], 1, false);
    }

    /**
     * The wait before the given attempt, one-based, or null when the plan is
     * exhausted. The last rung repeats rather than the ladder running off its
     * end, which is what the published ladder's doubled final entry means.
     */
    public function delayBefore(int $attempt): ?int
    {
        if ($attempt <= 1) {
            return 0;
        }

        if ($attempt > $this->maxAttempts) {
            return null;
        }

        if ($this->ladderSeconds === []) {
            return null;
        }

        $index = min($attempt - 1, \count($this->ladderSeconds)) - 1;

        return $this->ladderSeconds[$index];
    }

    /**
     * Whether this plan ever retries at all. A plan that does not, paired
     * with a failure policy that continues, is not the inert pairing the
     * save-time check refuses: it is an author saying so once, in one place.
     */
    public function retries(): bool
    {
        return $this->maxAttempts > 1;
    }

    /** The longest a step on this plan can stay in flight, ignoring jitter. */
    public function totalLadderSeconds(): int
    {
        $total = 0;

        for ($attempt = 2; $attempt <= $this->maxAttempts; $attempt++) {
            $total += $this->delayBefore($attempt) ?? 0;
        }

        return $total;
    }
}
