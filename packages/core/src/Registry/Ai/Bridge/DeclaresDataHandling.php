<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai\Bridge;

/**
 * A bridge that states what its route does with what it is sent.
 *
 * **Absent means** the site owner's privacy page and the published data
 * rubric answer read **"not declared by this route"**. That is a real answer
 * a site owner can give and defend, which is exactly what an absent
 * declaration has to produce.
 */
interface DeclaresDataHandling
{
    public function dataHandling(): DataHandling;
}
