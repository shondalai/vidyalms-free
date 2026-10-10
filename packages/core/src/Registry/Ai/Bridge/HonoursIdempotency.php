<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

/**
 * A bridge that will not charge twice for one idempotency key.
 *
 * **Absent means** the runner must not auto-retry a call that may already
 * have spent: the outbox parks instead and the run is recorded at-most-once.
 *
 * This is the capability whose absence is a commercial fact rather than a
 * technical one. A remote service that re-runs the model on a retry and
 * re-pays the upstream tokens must not declare it, because declaring it flips
 * the runner into auto-retry against a counterparty that charges twice.
 */
interface HonoursIdempotency
{
}
