<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

/**
 * A bridge that puts real token counts and a real cost on its result.
 *
 * **Absent means** tokens and cost are recorded as **unknown**, never as
 * zero. The distinction is the difference between a usage report that says a
 * site spent nothing and one that says it does not know, and only the second
 * is honest about a bring-your-own-key route.
 */
interface ReportsUsage
{
}
