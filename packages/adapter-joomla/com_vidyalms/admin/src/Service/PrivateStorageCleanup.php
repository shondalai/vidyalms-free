<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service;

defined('_JEXEC') or die;

use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaStorage;

/** @license GNU General Public License version 3 or later; see LICENSE */
final class PrivateStorageCleanup
{
    /** Never create/claim a root, follow links, or remove the root and its metadata. */
    public static function remove(JoomlaStorage $storage, bool $removeLearnerFiles = false): int
    {
        $storage->privatePath('.uninstall-health');
        $trees = ['reports', 'certificates', 'imports'];
        if ($removeLearnerFiles) {
            array_push($trees, 'submissions', 'attempts');
        }
        $removed = 0;
        foreach ($trees as $tree) {
            $path = $storage->privatePath($tree);
            if (is_dir($path)) {
                $removed += self::removeDirectory($storage, $tree);
            } elseif (file_exists($path)) {
                // A malformed tree is evidence for the server owner, not a
                // reason to guess which file the installer ought to destroy.
                throw new \RuntimeException('Private cleanup expected a directory.');
            }
        }
        return $removed;
    }

    private static function removeDirectory(JoomlaStorage $storage, string $relative): int
    {
        $path = $storage->privatePath($relative);
        $removed = 0;
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $entry) {
            $child = $relative . '/' . $entry->getFilename();
            // Revalidate every descendant, including Windows aliases, before I/O.
            $absolute = $storage->privatePath($child);
            if (is_dir($absolute)) {
                $removed += self::removeDirectory($storage, $child);
                continue;
            }
            $stat = @lstat($absolute);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1 || !@unlink($absolute)) {
                throw new \RuntimeException('Private cleanup could not safely remove a file.');
            }
            $removed++;
        }
        if (!@rmdir($path)) {
            throw new \RuntimeException('Private cleanup could not remove a directory.');
        }
        return $removed;
    }
}
