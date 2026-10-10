<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/** Optional administrator-facing help for a registered helper. */
interface DescribesAiPlacement
{
    /** Catalogue key explaining what the helper offers. */
    public function description(): string;
}
