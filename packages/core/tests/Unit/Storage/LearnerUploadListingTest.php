<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Infrastructure\Storage\LearnerUploadListing;
use Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalStorageSafety;

final class LearnerUploadListingTest extends TestCase
{
    public function testOnlyTheRequestedLearnersPrivateWorkIsListed(): void
    {
        $root = sys_get_temp_dir() . '/vidyalms-list-' . bin2hex(random_bytes(8));
        $files = ['submissions/50/draft.pdf', 'attempts/50/answer.txt', 'submissions/51/other.pdf', 'lesson-files/course.txt'];
        foreach ($files as $path) {
            mkdir(dirname($root . '/' . $path), 0700, true);
            file_put_contents($root . '/' . $path, 'private');
        }
        $storage = new class ($root) {
            use LearnerUploadListing;
            public function __construct(private readonly string $root) {}
            public function privatePath(string $path): string { return LocalStorageSafety::path($this->root, $path); }
        };
        try {
            $paths = iterator_to_array($storage->learnerUploads(50), false);
            sort($paths);
            self::assertSame(['attempts/50/answer.txt', 'submissions/50/draft.pdf'], $paths);
            self::assertSame([], iterator_to_array($storage->learnerUploads(99), false));
        } finally {
            foreach ($files as $path) { unlink($root . '/' . $path); }
            foreach (['submissions/50', 'submissions/51', 'submissions', 'attempts/50', 'attempts', 'lesson-files'] as $path) { rmdir($root . '/' . $path); }
            rmdir($root);
        }
    }
}
