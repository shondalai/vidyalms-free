<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

interface SchemaVersionRepository
{
    /**
     * Records the version once; recording an already known version changes
     * nothing.
     */
    public function recordOnce(string $version): void;

    public function has(string $version): bool;
}
