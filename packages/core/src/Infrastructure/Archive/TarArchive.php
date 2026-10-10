<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Archive;

/**
 * A read-only tar reader for an archive that arrives as bytes rather than as
 * a file on disk.
 *
 * It exists because StoragePort hands the core a string, not a path: private
 * storage may be S3, and PharData needs a local filename. Reading the archive
 * here also means the untrusted entry names are never handed to the
 * filesystem at all, which removes the entire class of extraction bugs rather
 * than defending against them.
 *
 * ## What it refuses, and why the whole archive dies with it
 *
 * An entry name that is absolute or contains a ".." segment is not a
 * mistake, it is an attack on whoever extracts this archive next. The same
 * goes for a member that inflates far past what a course backup can be. Both
 * refuse the archive rather than skipping the entry: an importer that quietly
 * carried on would import the harmless half of a hostile file and report
 * success.
 *
 * ## Limits
 *
 * The caller's byte cap is only half of the limit. The other half is what
 * this process can actually hold: a 256 MB cap on a site with a 128 MB
 * memory_limit refuses nothing, because the allocation that crosses the limit
 * is a fatal error no catch block ever sees.
 *
 * So the archive is never held whole. ArchiveStream decompresses it a piece
 * at a time, this reader walks the tar headers as those pieces arrive, and
 * only the entries themselves are kept. Both limits are enforced while the
 * bytes arrive: the total against what has actually been inflated, and every
 * allocation against the memory the process has left at that moment. The gzip
 * trailer declares what a member decompresses to and is refused up front when
 * it already declares too much, but it is never believed in the other
 * direction: an archive that understates itself is stopped at the piece that
 * crosses the cap.
 *
 * The entry count is capped for the same reason: a million empty members cost
 * nothing to compress.
 */
final class TarArchive
{
    public const DEFAULT_MAX_BYTES = 268435456;
    public const DEFAULT_MAX_ENTRIES = 20000;

    private const BLOCK = 512;

    private const GZIP_MAGIC = "\x1f\x8b";
    private const ZIP_MAGIC = "PK\x03\x04";

    /**
     * @param array<string, string> $entries entry path to contents
     */
    private function __construct(
        private readonly array $entries,
    ) {
    }

    /**
     * @param int $maxBytes   the largest decompressed archive accepted
     * @param int $maxEntries the largest number of members accepted
     */
    public static function open(
        string $raw,
        int $maxBytes = self::DEFAULT_MAX_BYTES,
        int $maxEntries = self::DEFAULT_MAX_ENTRIES,
    ): self {
        return new self(self::index(self::stream($raw, $maxBytes), $maxEntries));
    }

    public function read(string $path): string
    {
        return $this->readOrNull($path)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw new \RuntimeException(sprintf('The archive has no entry "%s".', $path));
    }

    public function readOrNull(string $path): ?string
    {
        return $this->entries[$path] ?? null;
    }

    /**
     * Every entry, in archive order.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return array_keys($this->entries);
    }

    private static function stream(string $raw, int $maxBytes): ArchiveStream
    {
        if ($raw === '') {
            throw new \RuntimeException('The archive is empty.');
        }

        if (str_starts_with($raw, self::ZIP_MAGIC)) {
            throw new \RuntimeException(
                'This .mbz is a ZIP archive. Only the gzipped tar format Moodle writes today is supported; '
                . 're-export the course from a current Moodle, or repack the file as .tar.gz.',
            );
        }

        $compressed = str_starts_with($raw, self::GZIP_MAGIC);

        if ($compressed) {
            self::refuseDeclaredSize($raw, $maxBytes);
        }

        return ArchiveStream::over($raw, $compressed, $maxBytes);
    }

    /**
     * The trailer is a claim, so it may only refuse: an archive claiming
     * gigabytes is stopped before a single byte is inflated, and one claiming
     * to be small is measured piece by piece as it inflates instead.
     */
    private static function refuseDeclaredSize(string $raw, int $maxBytes): void
    {
        if (\strlen($raw) < 18) {
            return;
        }

        $trailer = unpack('Vsize', substr($raw, -4));

        // The size is modulo four gigabytes, so it is a floor and never a
        // guarantee, which is the other reason it may only refuse.
        if ($trailer !== false && (int) $trailer['size'] > $maxBytes) {
            throw new \RuntimeException(sprintf(
                'The archive expands past the %d byte limit and was refused.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $maxBytes,
            ));
        }
    }

    /**
     * @return array<string, string>
     */
    private static function index(ArchiveStream $stream, int $maxEntries): array
    {
        $entries = [];
        $longName = null;

        while (($header = self::header($stream)) !== null) {
            if (trim($header, "\0") === '') {
                continue;
            }

            self::requireChecksum($header);

            $type = substr($header, 156, 1);
            $size = self::size($header);

            if ($type === 'L') {
                $longName = rtrim(self::body($stream, $size), "\0");
                self::requireSafePath($longName);
                continue;
            }

            if ($type === 'x' || $type === 'g' || $type === 'K') {
                self::discard($stream, $size);
                continue;
            }

            $name = $longName ?? self::entryName($header);
            $longName = null;
            self::requireSafePath($name);

            if ($type === '5' || str_ends_with($name, '/')) {
                self::discard($stream, $size);
                continue;
            }

            if ($type !== '0' && $type !== "\0" && $type !== '') {
                self::discard($stream, $size);
                continue;
            }

            if (\count($entries) >= $maxEntries) {
                throw new \RuntimeException(sprintf(
                    'The archive holds more than the %d entries allowed and was refused.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $maxEntries,
                ));
            }

            $entries[$name] = self::body($stream, $size);
        }

        return $entries;
    }

    /**
     * The next header block, or null once fewer than a block is left. A tar
     * ends in zero blocks and may be padded past them, so a short tail is the
     * end of the archive rather than an error.
     */
    private static function header(ArchiveStream $stream): ?string
    {
        $block = $stream->take(self::BLOCK);

        return \strlen($block) === self::BLOCK ? $block : null;
    }

    /**
     * One entry's contents, with the padding to the next block boundary
     * consumed behind it. A member that runs past the end of the archive is
     * refused here, which is where a size larger than what is left dies.
     */
    private static function body(ArchiveStream $stream, int $size): string
    {
        $contents = $stream->take($size);

        if (\strlen($contents) !== $size) {
            throw self::badSize('runs past the end of the archive');
        }

        $stream->skip(self::padding($size));

        return $contents;
    }

    private static function discard(ArchiveStream $stream, int $size): void
    {
        if ($stream->skip($size) !== $size) {
            throw self::badSize('runs past the end of the archive');
        }

        $stream->skip(self::padding($size));
    }

    private static function padding(int $size): int
    {
        return (self::BLOCK - $size % self::BLOCK) % self::BLOCK;
    }

    private static function entryName(string $header): string
    {
        $name = self::field($header, 0, 100);
        $prefix = self::field($header, 345, 155);

        return $prefix === '' ? $name : $prefix . '/' . $name;
    }

    private static function requireSafePath(string $path): void
    {
        if (trim($path) === '') {
            throw new \RuntimeException('The archive holds an entry with no name.');
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('#^[a-z]:[\\\\/]#i', $path) === 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException(sprintf('The archive entry "%s" is an absolute path and was refused.', $path));
        }

        foreach (preg_split('#[\\\\/]+#', $path) ?: [] as $segment) {
            if ($segment === '..') {
                throw new \RuntimeException(sprintf(
                    'The archive entry "%s" points outside the archive and was refused.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $path,
                ));
            }
        }
    }

    private static function requireChecksum(string $header): void
    {
        $stored = self::field($header, 148, 8);

        if ($stored === '' || preg_match('/^[0-7]+$/', $stored) !== 1) {
            throw new \RuntimeException('The archive has a tar header with no checksum.');
        }

        $blanked = substr_replace($header, '        ', 148, 8);
        $unsigned = 0;
        $signed = 0;

        for ($position = 0; $position < self::BLOCK; $position++) {
            $byte = \ord($blanked[$position]);
            $unsigned += $byte;
            $signed += $byte > 127 ? $byte - 256 : $byte;
        }

        $expected = (int) octdec($stored);

        if ($expected !== $unsigned && $expected !== $signed) {
            throw new \RuntimeException('The archive has a corrupt tar header.');
        }
    }

    /**
     * Sizes are octal, except that GNU writes anything over eight gigabytes
     * as big-endian binary with the high bit of the first byte set. That form
     * is two's complement, so it can encode a negative number, and eleven
     * bytes hold more than a PHP integer does: both are refused here rather
     * than allowed to wrap into a size that walks the reader backwards.
     */
    private static function size(string $header): int
    {
        $raw = substr($header, 124, 12);

        if ((\ord($raw[0]) & 0x80) !== 0) {
            return self::requirePositive(self::base256($raw));
        }

        $digits = trim($raw, "\0 ");

        if ($digits === '') {
            return 0;
        }

        if (preg_match('/^[0-7]+$/', $digits) !== 1) {
            throw new \RuntimeException('The archive has a tar header with an unreadable size.');
        }

        $size = octdec($digits);

        if (!\is_int($size)) {
            throw self::badSize('is too large to read');
        }

        return self::requirePositive($size);
    }

    private static function base256(string $raw): int
    {
        // Bit 6 of the flag byte is the sign of the two's complement number,
        // so 0xFF opens a negative size and nothing else does.
        if ((\ord($raw[0]) & 0x40) !== 0) {
            throw self::badSize('is negative');
        }

        $size = 0;

        for ($position = 1; $position < 12; $position++) {
            $byte = \ord($raw[$position]);

            if ($size > intdiv(\PHP_INT_MAX - $byte, 256)) {
                throw self::badSize('is too large to read');
            }

            $size = $size * 256 + $byte;
        }

        return $size;
    }

    private static function requirePositive(int $size): int
    {
        if ($size < 0) {
            throw self::badSize('is negative');
        }

        return $size;
    }

    private static function badSize(string $why): \RuntimeException
    {
        return new \RuntimeException(sprintf('The archive has a tar header whose size %s, and was refused.', $why));
    }

    private static function field(string $header, int $offset, int $length): string
    {
        return trim(substr($header, $offset, $length), "\0 ");
    }
}
