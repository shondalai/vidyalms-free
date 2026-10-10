<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

/**
 * A bridge that can turn text into vectors.
 *
 * **Absent means** grounded retrieval and vector duplicate detection are
 * **unavailable on this route**, not silently degraded, and tasks declaring
 * `groundingMode() === Required` are hidden. A duplicate check that quietly
 * fell back to exact matching without saying so would report a clean bank it
 * never actually compared.
 */
interface Embeds
{
    /** The vector width, which a stored vector column has to agree with. */
    public function dimensions(): int;

    /** How many texts may be embedded in one call. */
    public function maxBatch(): int;
}
