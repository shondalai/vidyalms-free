<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assignments;

/**
 * Metadata of one uploaded file attached to a submission. The path is a
 * private storage path (registered after a signed upload), never a URL.
 */
final class SubmissionFile
{
    public function __construct(
        public readonly string $path,
        public readonly string $filename,
        public readonly int $sizeBytes,
        public readonly string $mime,
    ) {
    }

    public function extension(): string
    {
        $dot = strrpos($this->filename, '.');

        if ($dot === false || $dot === \strlen($this->filename) - 1) {
            return '';
        }

        return strtolower(substr($this->filename, $dot + 1));
    }
}
