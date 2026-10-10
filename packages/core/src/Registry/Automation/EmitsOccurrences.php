<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

/**
 * The action opens automation runs of its own.
 *
 * Absent means the action's effect stays inside the step: it may write rows,
 * send mail or call out, but nothing it does opens another run, so it can
 * never be a hop of a cascade.
 *
 * It exists because loop protection three used to be a substring scan for the
 * literal key `emit_event`, which a third-party action calling
 * OpenAutomationRuns simply walks past by being called something else. The
 * refusal that a cascading rule may not sit at cooldown zero now probes this
 * declaration with instanceof, so an action that cascades is caught by what it
 * declares rather than by a core file knowing its key.
 *
 * The stamping of cascade depth and chain is NOT the declarer's job. A caller
 * that had to stamp its own provenance could omit it, which is the failure the
 * inheritance exists to prevent, so the engine stamps it from the step frame
 * that is executing.
 */
interface EmitsOccurrences
{
    /**
     * The keys this action may emit, or the empty list when the key is
     * authored per step and cannot be declared up front.
     *
     * The builder shows it and the loop screen groups by it; nothing in the
     * engine dispatches on it, so an empty list costs an author a hint and
     * never a refusal.
     *
     * @return list<string>
     */
    public function emittedKeys(): array;
}
