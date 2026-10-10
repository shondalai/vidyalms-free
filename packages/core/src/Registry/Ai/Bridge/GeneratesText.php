<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

/**
 * A bridge that can answer with text.
 *
 * **Absent means** the bridge serves no text task and is not selectable for
 * one. An embedding-only route is the ordinary case, and a route selected for
 * a text task it cannot serve would refuse at click time rather than being
 * absent from the chain.
 *
 * A marker, because the text path is the work method itself. There is nothing
 * a bridge could tell the runner about it that the result does not already
 * carry.
 */
interface GeneratesText
{
}
