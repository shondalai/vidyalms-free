<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * How much friction the accept step carries, taken from the published 1EdTech
 * tiers rather than invented.
 *
 * No tier permits automatic acceptance. The tier sets review depth and
 * nothing else, which is why there is no fourth case meaning "accept without
 * a person": a tier able to express that would be the automation-bias failure
 * with a settings screen in front of it.
 */
enum AiRiskTier: string
{
    /** Alt text, metadata, glossary. A light review panel. */
    case Low = 'low';

    /**
     * Lesson drafts, rubrics, formative practice, feedback drafts. A named
     * reviewer and a recorded decision. The absent default, so an author who
     * declares nothing gets the middle answer rather than the cheapest one.
     */
    case Medium = 'medium';

    /**
     * Anything targeting a bank a credential-bearing assessment draws from,
     * or a page published to inform the public. A named reviewer, a second
     * confirmation over the blocking findings, and the accepter stamped as
     * the person holding editorial responsibility.
     */
    case High = 'high';
}
