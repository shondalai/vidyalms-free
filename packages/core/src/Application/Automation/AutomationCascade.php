<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;

/**
 * The step frame that is executing right now, so a run opened from inside an
 * action inherits its cascade whether or not the action says so.
 *
 * **This is loop protection mechanism two moved off the caller.** The stamping
 * used to be the emitting action's job: `emit_event` called
 * `TriggerOccurrence::inheritingCascade()` and passed its own chain, and every
 * one of the three protections then rested on a third-party action choosing to
 * do the same. One that simply did not call it opened run after run at depth
 * zero with an empty chain, so the site ceiling was never approached and the
 * chain check had nothing to find. Measured: two mutually emitting rules at
 * cooldown zero opened one run per turn with no sign of stopping.
 *
 * So the engine holds the frame instead. RunAutomationStep enters the frame
 * before it calls execute() and leaves it afterwards, and OpenAutomationRuns
 * reads it when no explicit frame is passed. An action that opens runs by any
 * route, including one that never heard of cascade depth, is stamped with the
 * depth and chain of the step that is calling it.
 *
 * The stack is real because a step may execute inside another step's frame on
 * the same runner, and unwinding to the wrong frame would restore a shallower
 * depth than the one actually in force.
 */
final class AutomationCascade
{
    /** @var list<AutomationActionContext> */
    private array $frames = [];

    /**
     * Runs the operation inside this step's frame, and leaves the frame even
     * when the effect throws.
     *
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public function within(AutomationActionContext $context, callable $operation): mixed
    {
        $this->frames[] = $context;

        try {
            return $operation();
        } finally {
            array_pop($this->frames);
        }
    }

    /** The step frame in force, or null when nothing is executing. */
    public function current(): ?AutomationActionContext
    {
        $depth = \count($this->frames);

        return $depth === 0 ? null : $this->frames[$depth - 1];
    }

    /** How deep the frames go, for a test and for the run log. */
    public function depth(): int
    {
        return \count($this->frames);
    }
}
