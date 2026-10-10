<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

/**
 * One file attached to a submission a grader is about to read. The stored
 * path stays on this side of the wire: the presenter turns it into a signed,
 * expiring URL and emits that instead.
 */
final class GradebookFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $filename,
        public readonly int $sizeBytes,
        public readonly string $mime,
    ) {
    }
}
