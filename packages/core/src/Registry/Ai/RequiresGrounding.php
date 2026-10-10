<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * A task that reads a grounding set and cites it.
 *
 * **Absent means** AiGroundingMode::None, and no citation is expected or
 * checked.
 */
interface RequiresGrounding
{
    public function groundingMode(): AiGroundingMode;

    /**
     * What one citation names, such as `chunk` or `lesson`, so a coverage
     * check knows what it is counting.
     */
    public function citationUnit(): string;
}
