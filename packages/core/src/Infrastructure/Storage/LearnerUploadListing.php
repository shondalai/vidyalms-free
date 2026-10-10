<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Storage;

/** Uses the adapter's existing private-root and path authorization checks. */
trait LearnerUploadListing
{
    abstract public function privatePath(string $path): string;

    /** @return iterable<string> */
    public function learnerUploads(int $userId): iterable
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException('A learner is required.');
        }
        foreach (['submissions', 'attempts'] as $purpose) {
            $prefix = $purpose . '/' . $userId;
            $root = $this->privatePath($prefix);
            if (!is_dir($root)) {
                continue;
            }
            $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($entries as $entry) {
                LocalStorageSafety::noLinks($entry->getPathname());
                if (!$entry->isFile()) {
                    continue;
                }
                $relative = str_replace('\\', '/', substr($entry->getPathname(), strlen($root) + 1));
                yield LocalStorageSafety::downloadable($prefix . '/' . $relative);
            }
        }
    }
}
