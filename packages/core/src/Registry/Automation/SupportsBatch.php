<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

/**
 * Many subjects can share one queue row.
 *
 * Absent means the fan-out issues one queue row per subject, which is the
 * shipped behaviour and the safe one: one row per subject is one idempotency
 * claim per subject, and the canonical digest carries the subject id precisely
 * so a four hundred subject fan-out cannot collapse onto one claim.
 *
 * **Nothing in the engine probes this yet, and declaring it changes no
 * behaviour.** The batch path is not built: the fan-out issues one row per
 * subject whether or not an action declares this, so the published absent
 * meaning and the published present meaning are the same thing today. It is
 * read by the builder, which shows the declaration and the maximum, and it is
 * stated here so a provider author is not misled into expecting the engine to
 * act on it.
 */
interface SupportsBatch
{
    /** How many subjects one row of this action may carry. */
    public function maximumBatchSize(): int;
}
