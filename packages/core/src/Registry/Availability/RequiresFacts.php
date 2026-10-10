<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

/**
 * The preloaded fact sets this leaf reads, so the composer loads them once
 * for the whole render set. Default when absent: the leaf reads no preloaded
 * fact set and may issue no query of its own.
 *
 * A third-party leaf contributes to the preload rather than issuing a query
 * per item, which is what keeps one outline render inside the query budget
 * whatever is installed. A declared key no loader answers is a save-time
 * refusal rather than a silent null.
 */
interface RequiresFacts
{
    /**
     * @return list<string>
     */
    public function requiredFacts(): array;
}
