<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Upload;

/** A host stores uploads under a lock and applies cumulative limits. */
interface UploadStorage
{
    public function putUpload(string $path, string $contents, bool $public, int $userId): void;
}
