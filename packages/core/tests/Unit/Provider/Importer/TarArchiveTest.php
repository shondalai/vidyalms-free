<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\Importer;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Infrastructure\Archive\TarArchive;
use Shondalai\VidyaLMS\Core\Tests\Support\TarWriter;

/**
 * The archive reader an uploaded .mbz goes through. Every case here is about
 * what it refuses: an importer that trusted entry names would hand a hostile
 * archive straight to whatever extracts it next.
 */
final class TarArchiveTest extends TestCase
{
    /**
     * ArchiveStream::RESERVE, the memory it refuses to spend on an archive
     * whatever the limit says. It is what these tests measure a refusal
     * against: a reader that used it would have nothing left to report the
     * refusal with.
     */
    private const RESERVE = 4 * 1024 * 1024;


    public function testItReadsTheEntriesOfAGzippedTar(): void
    {
        $archive = TarArchive::open(TarWriter::gzip([
            'moodle_backup.xml' => '<moodle_backup/>',
            'course/course.xml' => '<course id="2"/>',
            'files/ab/abcdef' => 'bytes',
        ]));

        self::assertSame(['moodle_backup.xml', 'course/course.xml', 'files/ab/abcdef'], $archive->paths());
        self::assertSame('<course id="2"/>', $archive->read('course/course.xml'));
        self::assertSame('bytes', $archive->read('files/ab/abcdef'));
        self::assertNull($archive->readOrNull('nothing.xml'));
    }

    public function testItReadsAnArchiveThatWasNeverCompressed(): void
    {
        $archive = TarArchive::open(TarWriter::tar(['moodle_backup.xml' => '<moodle_backup/>']));

        self::assertSame('<moodle_backup/>', $archive->read('moodle_backup.xml'));
    }

    public function testAnEntryThatEscapesTheArchiveIsRefused(): void
    {
        $this->expectExceptionMessage('points outside the archive');

        TarArchive::open(TarWriter::gzip([
            'moodle_backup.xml' => '<moodle_backup/>',
            '../../etc/cron.d/payload' => 'rooted',
        ]));
    }

    public function testAnAbsoluteEntryIsRefused(): void
    {
        $this->expectExceptionMessage('is an absolute path');

        TarArchive::open(TarWriter::gzip(['/etc/passwd' => 'rooted']));
    }

    public function testAWindowsAbsoluteEntryIsRefused(): void
    {
        $this->expectExceptionMessage('is an absolute path');

        TarArchive::open(TarWriter::gzip(['C:\\windows\\system32\\payload' => 'rooted']));
    }

    public function testAnArchiveThatExpandsPastTheLimitIsRefusedWhileItInflates(): void
    {
        $bomb = TarWriter::gzip(['moodle_backup.xml' => str_repeat("\0", 8 * 1024 * 1024)]);

        self::assertLessThan(1024 * 1024, \strlen($bomb), 'The fixture must be small compressed to be worth refusing.');
        $this->expectExceptionMessage('expands past the 1048576 byte limit');

        TarArchive::open($bomb, maxBytes: 1024 * 1024);
    }

    /**
     * The gzip trailer says how much the member decompresses to, and a hostile
     * archive lies about it. The size is measured as it inflates for that
     * reason, so the lie buys nothing.
     */
    public function testAnArchiveThatUnderstatesItsSizeIsStillRefusedChunkByChunk(): void
    {
        $bomb = TarWriter::gzip(['moodle_backup.xml' => str_repeat("\0", 8 * 1024 * 1024)]);
        $lying = substr($bomb, 0, -4) . pack('V', 4096);

        $this->expectExceptionMessage('expands past the 1048576 byte limit');

        TarArchive::open($lying, maxBytes: 1024 * 1024);
    }

    /**
     * Nothing the archive says about its own size is believed, in the trailer
     * or in the tar header: this entry declares sixty four bytes and carries
     * eight megabytes, and the refusal comes from measuring what actually
     * inflated.
     */
    public function testAnEntryWhoseHeaderUnderstatesItIsStillRefusedAsItInflates(): void
    {
        $bomb = TarWriter::compress(TarWriter::tarWithRawSize(
            'moodle_backup.xml',
            sprintf('%011o', 64) . "\0",
            str_repeat("\0", 8 * 1024 * 1024),
        ));

        self::assertLessThan(1024 * 1024, \strlen($bomb), 'The fixture must be small compressed to be worth refusing.');
        $this->expectExceptionMessage('expands past the 1048576 byte limit');

        TarArchive::open($bomb, maxBytes: 1024 * 1024);
    }

    /**
     * The cap that matters is not the caller's, it is the one memory_limit
     * sets: a 256 MB cap on a process with less than that left refuses
     * nothing, because the allocation that crosses the limit is a fatal error
     * no catch block sees. This test runs with a deliberately small limit, so
     * a reader without the memory cap does not fail it, it kills the suite.
     */
    public function testASmallArchiveThatWouldOutgrowTheProcessIsRefusedWithoutExhaustingIt(): void
    {
        $bomb = TarWriter::gzip(['moodle_backup.xml' => str_repeat("\0", 32 * 1024 * 1024)]);

        self::assertLessThan(128 * 1024, \strlen($bomb), 'The fixture must be small enough to arrive as an upload.');

        $limit = (string) ini_get('memory_limit');
        $headroom = 16 * 1024 * 1024;
        $before = memory_get_usage(true);
        ini_set('memory_limit', (string) ($before + $headroom));

        try {
            // The caller's cap is the 256 MB default and does not bind here.
            TarArchive::open($bomb);
            self::fail('An archive larger than the process can hold must be refused.');
        } catch (\RuntimeException $failure) {
            self::assertStringContainsString('memory for', $failure->getMessage());
        } finally {
            ini_set('memory_limit', $limit);
        }

        self::assertLessThanOrEqual(
            $headroom - self::RESERVE,
            memory_get_usage(true) - $before,
            'The refusal comes before the archive is held, and leaves the reserve it promises.',
        );
    }

    /**
     * The other direction, and it is a failure of the same size: a memory cap
     * that refuses a backup the process demonstrably has room for has lost the
     * customer their migration just as surely as an OOM would have. This
     * archive is a third of the headroom, it is read whole under a limit that
     * low, and the assertions afterwards show the process was never near the
     * limit while it did.
     */
    public function testAnArchiveTheProcessHasRoomForIsReadRatherThanRefused(): void
    {
        $size = 11 * 1024 * 1024;
        $archive = TarWriter::gzip(['moodle_backup.xml' => str_repeat('t', $size)]);

        $limit = (string) ini_get('memory_limit');
        $headroom = 32 * 1024 * 1024;
        $before = memory_get_usage(true);
        ini_set('memory_limit', (string) ($before + $headroom));

        try {
            // The caller's cap is the 256 MB default, so what is being tested
            // is the memory cap and nothing else.
            $read = TarArchive::open($archive);
            $entry = $read->read('moodle_backup.xml');
            $used = memory_get_usage(true) - $before;
        } finally {
            ini_set('memory_limit', $limit);
        }

        self::assertSame($size, \strlen($entry), 'The whole entry is there.');
        self::assertLessThan(
            $headroom,
            $used,
            'The read stayed inside the limit it was given, which is what "has room for it" means.',
        );
    }

    /**
     * The two halves of the attack together, which is what a queued import on
     * a live site met: a trailer that understates the archive so the up front
     * refusal never fires, and a member far larger than the process can hold
     * so the reader that believed the trailer dies where nothing can report
     * it.
     */
    public function testAnArchiveThatLiesAboutItsSizeIsRefusedByTheMemoryTheProcessHasLeft(): void
    {
        $bomb = TarWriter::gzip(['moodle_backup.xml' => str_repeat("\0", 32 * 1024 * 1024)]);
        $lying = substr($bomb, 0, -4) . pack('V', 4096);

        $limit = (string) ini_get('memory_limit');
        $headroom = 16 * 1024 * 1024;
        $before = memory_get_usage(true);
        ini_set('memory_limit', (string) ($before + $headroom));

        try {
            TarArchive::open($lying);
            self::fail('An archive larger than the process can hold must be refused.');
        } catch (\RuntimeException $failure) {
            self::assertStringContainsString('memory for', $failure->getMessage());
        } finally {
            ini_set('memory_limit', $limit);
        }

        self::assertLessThanOrEqual(
            $headroom - self::RESERVE,
            memory_get_usage(true) - $before,
            'The refusal comes before the archive is held, and leaves the reserve it promises.',
        );
    }

    public function testAnArchiveWithMoreEntriesThanAllowedIsRefused(): void
    {
        $entries = [];

        for ($index = 0; $index < 12; $index++) {
            $entries['files/aa/file' . $index] = 'x';
        }

        $this->expectExceptionMessage('more than the 5 entries allowed');

        TarArchive::open(TarWriter::gzip($entries), maxEntries: 5);
    }

    public function testAZipDisguisedAsAnMbzIsRefusedWithAnAnswer(): void
    {
        $this->expectExceptionMessage('gzipped tar format');

        TarArchive::open("PK\x03\x04" . str_repeat('x', 64));
    }

    public function testAnEmptyFileIsRefused(): void
    {
        $this->expectExceptionMessage('The archive is empty.');

        TarArchive::open('');
    }

    /**
     * The crafted archive: a valid checksum over a base 256 size field whose
     * low sixty four bits are -1024, which is one block backwards. Read
     * without a guard, the offset never moves and index() pins a core until
     * the process is killed, which on the queued path is never.
     */
    public function testANegativeBase256SizeIsRefusedInsteadOfSpinningForever(): void
    {
        $archive = TarWriter::tarWithRawSize('moodle_backup.xml', TarWriter::base256Size(-1024));

        self::assertLessThan(2048, \strlen($archive), 'The whole attack is under two kilobytes.');

        $started = hrtime(true);

        try {
            TarArchive::open($archive);
            self::fail('A negative size must be refused.');
        } catch (\RuntimeException $failure) {
            self::assertStringContainsString('negative', $failure->getMessage());
        }

        self::assertLessThan(
            5.0,
            (hrtime(true) - $started) / 1e9,
            'The refusal must be immediate, not the first pause in an endless loop.',
        );
    }

    public function testABase256SizeLargerThanAPhpIntegerIsRefused(): void
    {
        // The flag bit alone, then eleven bytes of ones: 2^88 - 1, which
        // wraps to a small or negative number if it is read without care.
        $archive = TarWriter::tarWithRawSize('moodle_backup.xml', "\x80" . str_repeat("\xFF", 11));

        $this->expectExceptionMessage('too large to read');

        TarArchive::open($archive);
    }

    public function testASizeLargerThanTheArchiveItselfIsRefused(): void
    {
        $archive = TarWriter::tarWithRawSize('moodle_backup.xml', sprintf('%011o', 4194304) . "\0", 'four bytes');

        $this->expectExceptionMessage('runs past the end of the archive');

        TarArchive::open($archive);
    }

    public function testACorruptHeaderIsRefused(): void
    {
        $tar = TarWriter::tar(['moodle_backup.xml' => '<moodle_backup/>']);
        $corrupt = substr_replace($tar, 'zzzzzzzz', 148, 8);

        $this->expectExceptionMessage('checksum');

        TarArchive::open($corrupt);
    }
}
