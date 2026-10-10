<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Storage;

use Shondalai\VidyaLMS\Core\Http\ApiProblem;

/**
 * Counts real files, including abandoned uploads; explicit deletion releases capacity.
 * Age alone must never reclaim capacity: uploads have no attachment ledger and
 * answer/submission writers do not share this lock. An unreferenced-file scan
 * followed by unlink would race with those writers, even after 24 hours.
 */
final class LocalUploadQuota
{
    public const MAX_BYTES = 536870912;
    public const MAX_FILES = 500;
    public const MAX_HOURLY_FILES = 50;

    /** @param list<string> $roots */
    public function __construct(
        private readonly string $privateRoot,
        private readonly array $roots,
        private readonly int $maxBytes = self::MAX_BYTES,
        private readonly int $maxFiles = self::MAX_FILES,
        private readonly int $maxHourlyFiles = self::MAX_HOURLY_FILES,
    ) {
        if ($maxBytes <= 0 || $maxFiles <= 0 || $maxHourlyFiles <= 0) {
            throw new \InvalidArgumentException('Learner upload limits must be positive integers.');
        }
    }

    /**
     * Host hooks may override individual limits, never disable them with zero,
     * malformed values or an exception. Do not coerce floats/strings to integers.
     *
     * @return array{max_bytes: int, max_files: int, max_hourly_files: int}
     */
    public static function limits(mixed $configured = null): array
    {
        $limits = [
            'max_bytes' => self::MAX_BYTES,
            'max_files' => self::MAX_FILES,
            'max_hourly_files' => self::MAX_HOURLY_FILES,
        ];
        if (is_array($configured)) {
            foreach ($limits as $key => $fallback) {
                $value = $configured[$key] ?? null;
                if (is_int($value) && $value > 0) {
                    $limits[$key] = $value;
                }
            }
        }
        return $limits;
    }

    public function store(int $userId, int $bytes, callable $write): void
    {
        if ($userId <= 0) {
            throw ApiProblem::unauthorized();
        }
        $lockPath = LocalStorageSafety::path($this->privateRoot, '.upload-quota.lock');
        // The local quota ledger requires flock on the same file handle to prevent concurrent quota bypass.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot lock upload storage.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock upload storage.');
            }
            $total = 0;
            $count = 0;
            $recent = 0;
            foreach ($this->roots as $root) {
                // Enumerate every registered purpose, including extension purposes.
                foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $purpose) {
                    $directory = LocalStorageSafety::path($purpose, (string) $userId);
                    if (!is_dir($directory)) {
                        continue;
                    }
                    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
                    foreach ($files as $file) {
                        LocalStorageSafety::noLinks($file->getPathname());
                        if (!$file->isFile()) {
                            continue;
                        }
                        $total += $file->getSize();
                        ++$count;
                        $recent += $file->getMTime() >= time() - 3600 ? 1 : 0;
                        if ($count >= $this->maxFiles || $total + $bytes > $this->maxBytes || $recent >= $this->maxHourlyFiles) {
                            throw new ApiProblem(429, 'Upload limit reached', 'Your stored-file or hourly upload allowance is exhausted.');
                        }
                    }
                }
            }
            if ($bytes > $this->maxBytes) {
                throw new ApiProblem(429, 'Upload limit reached', 'Your stored-file allowance is exhausted.');
            }
            $write();
        } finally {
            flock($lock, LOCK_UN);
            // The local quota ledger requires flock on the same file handle to prevent concurrent quota bypass.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
            fclose($lock);
        }
    }
}
