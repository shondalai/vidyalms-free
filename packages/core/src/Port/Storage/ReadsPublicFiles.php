<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Storage;

/** Optional local-byte capability, not an HTTP client or arbitrary filesystem reader. */
interface ReadsPublicFiles
{
    /** Reject oversized files before materializing them; reject symlinks escaping the public root. */
    public function readPublic(string $path, int $maximumBytes): string;
}
