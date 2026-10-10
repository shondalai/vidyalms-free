<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;

final class FakeStorage implements StoragePort, \Shondalai\VidyaLMS\Core\Port\Storage\ListsLearnerUploads
{
    public function learnerUploads(int $userId): iterable
    {
        foreach (array_keys($this->privateFiles) as $path) {
            if (str_starts_with($path, 'submissions/' . $userId . '/') || str_starts_with($path, 'attempts/' . $userId . '/')) {
                yield $path;
            }
        }
    }
    /** @var array<string, string> */
    public array $publicFiles = [];

    /** @var array<string, string> */
    public array $privateFiles = [];

    public function putPublic(string $path, string $contents): void
    {
        $this->publicFiles[$path] = $contents;
    }

    public function putPrivate(string $path, string $contents): void
    {
        $this->privateFiles[$path] = $contents;
    }

    public function readPrivate(string $path): string
    {
        return $this->privateFiles[$path]
            ?? throw new \RuntimeException(sprintf('Private file "%s" does not exist.', $path));
    }

    public function exists(string $path): bool
    {
        return isset($this->publicFiles[$path]) || isset($this->privateFiles[$path]);
    }

    public function delete(string $path): void
    {
        unset($this->publicFiles[$path], $this->privateFiles[$path]);
    }

    public function publicUrl(string $path): string
    {
        return 'https://cms.test/media/vidyalms/' . $path;
    }

    public function signedUrl(string $path, int $expiresInSeconds): string
    {
        return 'https://cms.test/vidyalms/signed/' . $path . '?expires=' . $expiresInSeconds;
    }
}
