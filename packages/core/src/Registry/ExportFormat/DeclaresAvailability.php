<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ExportFormat;

/**
 * A format whose writer needs a runtime the host may not have.
 *
 * `csv` and `json` are written by this package's own code, so nothing about
 * them can be missing and they do not declare this. `pdf` needs a rendering
 * runtime and `xlsx` needs a spreadsheet writer, and a package registers those
 * formats whether or not the host was built with the library behind them:
 * registering conditionally would make the picker's answer depend on how the
 * host happened to be compiled, which is exactly what the widened catalogue
 * exists to stop.
 *
 * **So the format is registered and says whether it can write today.** The
 * catalogue publishes the flag, the schedule surface refuses a schedule naming
 * an unavailable format at save time rather than at 03:00, and the export path
 * still refuses the run with the writer's own reason. A control offering a
 * format nobody can write is a control that produces one failed run per night
 * and no explanation.
 *
 * **Miss direction: an absent declaration means available.** A format that
 * cannot be unavailable must not have to say so, or every existing writer would
 * be a core edit away from the flag.
 */
interface DeclaresAvailability
{
    /** Whether this host can write this format right now. */
    public function isAvailable(): bool;

    /**
     * Why not, in one clause a person can read, or null when it can.
     *
     * It names what is missing rather than saying the format is unavailable,
     * because the administrator reading it is the person who can install it.
     */
    public function unavailableReason(): ?string;
}
