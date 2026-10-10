<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

/**
 * Which stored payload versions this provider can read. Default when absent:
 * [1], so a newer payload routes to the miss path rather than being misread.
 *
 * This is the case a plain has() check cannot see. A paid package decorating
 * a free condition through replace() means removing the package re-registers
 * the free provider under the same key, get() resolves, and a payload written
 * by the paid version reaches a free evaluator that cannot read its own
 * fields. A resolvable key carrying an unsupported payload version is a miss
 * and is treated identically to a missing provider.
 */
interface SupportsPayloadVersions
{
    /**
     * @return list<int>
     */
    public function supportedPayloadVersions(): array;
}
