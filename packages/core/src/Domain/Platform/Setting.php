<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

/**
 * A named configuration value. Wrapping the value lets callers distinguish
 * "not set" (no Setting) from "set to null" (a Setting holding null).
 */
final class Setting
{
    public function __construct(
        public readonly string $name,
        public readonly mixed $value,
    ) {
    }
}
