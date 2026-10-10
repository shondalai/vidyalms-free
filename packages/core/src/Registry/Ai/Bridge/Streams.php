<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

/**
 * A bridge that can deliver its answer as it arrives.
 *
 * **Absent means** the placement falls back to buffered and says so; nothing
 * stored differs. Streaming is a presentation affordance on exactly one
 * surface and never a property of the record, which is why no stored shape
 * anywhere carries a partial answer.
 *
 * There is deliberately no streaming in the job path at all, so a queued run
 * ignores this capability entirely.
 */
interface Streams
{
}
