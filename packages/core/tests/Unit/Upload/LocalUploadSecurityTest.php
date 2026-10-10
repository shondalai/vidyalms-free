<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Upload;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalStorageSafety;
use Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalUploadQuota;
use Shondalai\VidyaLMS\Core\Upload\UploadedBytes;

final class LocalUploadSecurityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = str_replace('\\', '/', (string) realpath(sys_get_temp_dir())) . '/vidyalms-storage-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root . '/public');
        mkdir($this->root . '/private');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->root);
    }

    public function testPrivateRootsCannotBeInsideOrAbovePublicRoots(): void
    {
        foreach ([$this->root . '/public/private', $this->root . '/public/wp-admin', $this->root . '/public', $this->root] as $path) {
            try {
                LocalStorageSafety::privateRoot($path, [$this->root . '/public']);
                self::fail('Unsafe private root accepted.');
            } catch (\RuntimeException $failure) {
                self::assertStringContainsString('Private storage is disabled', $failure->getMessage());
            }
        }
        self::assertSame($this->root . '/private', LocalStorageSafety::privateRoot($this->root . '/private', [$this->root . '/public']));
    }

    public function testLogicalPathsRejectTraversalWindowsStreamsAndDeviceNames(): void
    {
        foreach (['../file', '/file', 'a//b', 'a/./b', 'a\\b', 'a:b', 'a.php::$DATA', 'NUL.txt', 'a/CON', 'a.', "a\0b"] as $path) {
            try {
                LocalStorageSafety::path($this->root . '/private', $path);
                self::fail('Unsafe logical path accepted: ' . $path);
            } catch (\InvalidArgumentException $failure) {
                self::assertSame('Unsafe storage path.', $failure->getMessage());
            }
        }
    }

    public function testAtomicWriteLeavesOnlyCompleteDestination(): void
    {
        $path = LocalStorageSafety::path($this->root . '/private', 'submissions/7/file.txt');
        LocalStorageSafety::write($path, 'first');
        LocalStorageSafety::write($path, 'second');
        self::assertSame('second', file_get_contents($path));
        self::assertSame([], glob(dirname($path) . '/.vidyalms-*.tmp'));
    }

    public function testQuotaCombinesPurposesAndPublicPrivateStorageAndReclaimsDeletedBytes(): void
    {
        $quota = new LocalUploadQuota($this->root . '/private', [$this->root . '/private', $this->root . '/public'], 10, 5, 5);
        $one = $this->root . '/private/submissions/7/one.txt';
        $two = $this->root . '/public/course-images/7/two.png';
        $quota->store(7, 6, static fn () => LocalStorageSafety::write($one, '123456'));
        try {
            $quota->store(7, 5, static fn () => LocalStorageSafety::write($two, '12345'));
            self::fail('Combined byte quota must refuse before writing.');
        } catch (ApiProblem $problem) {
            self::assertSame(429, $problem->status);
            self::assertFileDoesNotExist($two);
        }
        unlink($one);
        $quota->store(7, 5, static fn () => LocalStorageSafety::write($two, '12345'));
        self::assertFileExists($two);
        $quota->store(8, 6, fn () => LocalStorageSafety::write($this->root . '/private/submissions/8/other.txt', '123456'));
    }

    public function testHourlyAndFileCountCeilingsRefuseTinyFileFloods(): void
    {
        $one = $this->root . '/private/attempts/7/one.txt';
        LocalStorageSafety::write($one, '1');
        foreach ([[1, 50], [50, 1]] as [$files, $hourly]) {
            $quota = new LocalUploadQuota($this->root . '/private', [$this->root . '/private'], 100, $files, $hourly);
            try {
                $quota->store(7, 1, static fn () => self::fail('Quota called the write callback.'));
                self::fail('A tiny-file flood must be refused.');
            } catch (ApiProblem $problem) {
                self::assertSame(429, $problem->status);
            }
        }
    }

    public function testFailedWriteReleasesTheLockAndDoesNotConsumeQuota(): void
    {
        $quota = new LocalUploadQuota($this->root . '/private', [$this->root . '/private'], 1, 1, 1);
        try {
            $quota->store(7, 1, static fn () => throw new \RuntimeException('disk full'));
        } catch (\RuntimeException $failure) {
            self::assertSame('disk full', $failure->getMessage());
        }
        $quota->store(7, 1, fn () => LocalStorageSafety::write($this->root . '/private/attempts/7/ok.txt', 'x'));
        self::assertFileExists($this->root . '/private/attempts/7/ok.txt');
    }

    public function testHostLimitsAcceptPartialPositiveIntegerOverridesOnly(): void
    {
        $defaults = LocalUploadQuota::limits();
        self::assertSame(['max_bytes' => 536870912, 'max_files' => 500, 'max_hourly_files' => 50], $defaults);
        self::assertSame(
            ['max_bytes' => 1073741824, 'max_files' => 2, 'max_hourly_files' => 100],
            LocalUploadQuota::limits(['max_bytes' => 1073741824, 'max_files' => 2, 'max_hourly_files' => 100, 'unused' => 7]),
        );
        self::assertSame(array_replace($defaults, ['max_files' => 1]), LocalUploadQuota::limits(['max_files' => 1]));
        foreach ([null, false, '500', new \stdClass()] as $invalid) {
            self::assertSame($defaults, LocalUploadQuota::limits($invalid));
        }
        foreach ([null, 0, -1, true, 1.5, INF, '100', PHP_INT_MAX . '0', [], new \stdClass()] as $invalid) {
            self::assertSame($defaults, LocalUploadQuota::limits(array_fill_keys(array_keys($defaults), $invalid)));
        }
    }

    public function testInvalidDirectLimitsCannotDisableACeilingOnEmptyStorage(): void
    {
        foreach ([[0, 1, 1], [1, 0, 1], [1, 1, 0], [-1, 1, 1]] as [$bytes, $files, $hourly]) {
            try {
                new LocalUploadQuota($this->root . '/private', [$this->root . '/private'], $bytes, $files, $hourly);
                self::fail('Invalid limits were accepted.');
            } catch (\InvalidArgumentException $failure) {
                self::assertStringContainsString('positive integers', $failure->getMessage());
            }
        }
    }

    public function testOldFilesStillConsumeQuotaAndAreNeverDeletedByAge(): void
    {
        $old = $this->root . '/private/attempts/7/2020-01/old.txt';
        LocalStorageSafety::write($old, 'keep');
        touch($old, time() - 90000);
        $quota = new LocalUploadQuota($this->root . '/private', [$this->root . '/private'], 4, 1, 1);
        try {
            $quota->store(7, 1, static fn () => self::fail('Old files must still count.'));
            self::fail('Quota was bypassed by ageing a file.');
        } catch (ApiProblem $problem) {
            self::assertSame(429, $problem->status);
        }
        self::assertSame('keep', file_get_contents($old));
        // Age can release the hourly allowance, but never the byte/file allowance.
        $quota = new LocalUploadQuota($this->root . '/private', [$this->root . '/private'], 5, 2, 1);
        $quota->store(7, 1, fn () => LocalStorageSafety::write($this->root . '/private/submissions/7/new.txt', 'x'));
        self::assertSame('keep', file_get_contents($old));
    }

    public function testBoundedReadUsesActualBytesRatherThanReportedSize(): void
    {
        $path = $this->root . '/private/upload';
        file_put_contents($path, '12345');
        self::assertSame('12345', UploadedBytes::read($path, 5));
        $this->expectException(ApiProblem::class);
        UploadedBytes::read($path, 4);
    }

    public function testSymlinkCannotEscapeAStorageRoot(): void
    {
        $link = $this->root . '/private/link';
        if (!@symlink($this->root . '/public', $link) && DIRECTORY_SEPARATOR === '\\') {
            $process = proc_open([
                'cmd.exe', '/d', '/c', 'mklink', '/J', str_replace('/', '\\', $link),
                str_replace('/', '\\', $this->root . '/public'),
            ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (is_resource($process)) {
                foreach ($pipes as $pipe) {
                    stream_get_contents($pipe);
                    fclose($pipe);
                }
                proc_close($process);
            }
        }
        if (!is_dir($link)) {
            self::markTestSkipped('Creating symlinks or junctions is not permitted on this host.');
        }
        try {
            $this->expectException(\RuntimeException::class);
            LocalStorageSafety::path($this->root . '/private', 'link/payload.php');
        } finally {
            DIRECTORY_SEPARATOR === '\\' ? rmdir($link) : unlink($link);
        }
    }

    public function testConcurrentUploadsCannotBothSpendTheLastQuotaSlot(): void
    {
        $script = $this->root . '/worker.php';
        $loader = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
        file_put_contents($script, "<?php\nrequire " . $loader . ";\n" . <<<'PHP'
            $quota = new \Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalUploadQuota($argv[1], [$argv[1]], 1, 1, 1);
            try {
                $quota->store(7, 1, static function () use ($argv): void {
                    usleep(200000);
                    \Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalStorageSafety::write(
                        $argv[1] . '/submissions/7/' . bin2hex(random_bytes(4)) . '.txt', 'x'
                    );
                });
                echo 'stored';
            } catch (\Shondalai\VidyaLMS\Core\Http\ApiProblem $problem) {
                echo $problem->status === 429 ? 'limited' : 'unexpected';
            }
            PHP);
        $children = [];
        for ($i = 0; $i < 2; ++$i) {
            $process = proc_open([
                PHP_BINARY, '-c', (string) php_ini_loaded_file(), $script, $this->root . '/private',
            ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $children[] = [$process, $pipes];
        }
        $results = [];
        foreach ($children as [$process, $pipes]) {
            $results[] = stream_get_contents($pipes[1]);
            self::assertSame('', stream_get_contents($pipes[2]));
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process));
        }
        sort($results);
        self::assertSame(['limited', 'stored'], $results);
    }
}
