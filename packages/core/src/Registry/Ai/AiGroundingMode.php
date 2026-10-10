<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * Whether a task needs the grounding set, and how hard the need is.
 *
 * Required is the answer that hides a task on a route whose bridge declares
 * no Embeds capability, because grounded retrieval is unavailable on that
 * route rather than silently degraded. Optional runs either way and says
 * which it did.
 */
enum AiGroundingMode: string
{
    /** The absent default: no grounding, and no citation is expected or checked. */
    case None = 'none';

    /** Used when the route can supply it, and the run records which happened. */
    case Optional = 'optional';

    /** The task is not offered at all on a route that cannot ground it. */
    case Required = 'required';
}
