<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * Whether the run this task belongs to has been told to stop.
 *
 * A deadline is not a cancellation signal. A deadline answers "am I out of
 * time", which a task can only discover by asking, and it cannot express the
 * two cases that actually stop a run early: an operator hitting the stop
 * control, and a supervising pipeline abandoning a run whose siblings already
 * failed. A multi-step recipe checks this between steps; a task with an inner
 * loop checks it inside the loop.
 *
 * It is a value object over a predicate rather than a mutable flag so nothing
 * a task is handed can set it: a task observes cancellation and never causes
 * it.
 *
 * The default is never(), which is what every caller with nothing to cancel
 * against passes and what a task that never asks behaves like anyway.
 */
final class CancellationSignal
{
    /** @var callable(): bool */
    private $predicate;

    /**
     * @param callable(): bool $predicate
     */
    private function __construct(callable $predicate)
    {
        $this->predicate = $predicate;
    }

    /**
     * A run nothing can stop, which is the honest answer for a caller that
     * has no stop control behind it.
     */
    public static function never(): self
    {
        return new self(static fn (): bool => false);
    }

    /**
     * A run already abandoned when the task was handed it, which is what a
     * queue drain answers for a row whose run was stopped between the claim
     * and the dispatch.
     */
    public static function cancelled(): self
    {
        return new self(static fn (): bool => true);
    }

    /**
     * @param callable(): bool $predicate asked on every isCancelled() call, never cached
     */
    public static function from(callable $predicate): self
    {
        return new self($predicate);
    }

    /**
     * Asked rather than read, because a stop control set after the task
     * started is exactly the case this exists for.
     */
    public function isCancelled(): bool
    {
        return ($this->predicate)();
    }
}
