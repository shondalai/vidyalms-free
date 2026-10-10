<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

use Shondalai\VidyaLMS\Core\Registry\Ai\PromptSegment;

/**
 * A bridge that can screen segments before or after a call.
 *
 * **Absent means** no moderation pass runs and the run records
 * `moderation_unavailable` rather than pretending it passed.
 */
interface Moderates
{
    /**
     * @param list<PromptSegment> $segments
     */
    public function assess(array $segments, ModerationContext $context): ModerationFindings;
}
