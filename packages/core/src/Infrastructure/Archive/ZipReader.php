<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Archive;

/**
 * A read-only zip reader that never touches the filesystem, for the same reason
 * {@see TarArchive} does not: private storage may be S3, and PHP's own
 * ZipArchive needs a local filename.
 *
 * It also exists because `ext-zip` is not a dependency of this package and must
 * not become one. The core requires `ext-zlib`, which is what actually does the
 * work here: a zip entry is either stored or DEFLATE compressed per RFC 1951,
 * and `gzinflate()` is RFC 1951. Everything else in the format is offsets.
 *
 * ## What it reads and what it refuses
 *
 * Stored (method 0) and DEFLATE (method 8) entries, which is what every
 * exporter writes. A Zip64 archive is refused by name rather than misread: the
 * central directory locator is a different structure and reading the 32-bit
 * fields of a Zip64 archive gives plausible offsets into the wrong bytes.
 *
 * Entry names are refused if they are absolute or contain a parent traversal.
 * Nothing here writes a file, so a traversal cannot escape anywhere, but a name
 * like `../../etc/passwd` in a roster package is a hostile package and reading
 * it as a data file would put its content into a row report an operator reads.
 *
 * Sizes are bounded: an entry declaring an uncompressed size above the cap is
 * refused rather than inflated, so a zip bomb costs a refusal and not the
 * host's memory limit.
 */
final class ZipReader
{
    private const EOCD_SIGNATURE = "\x50\x4b\x05\x06";
    private const CENTRAL_SIGNATURE = "\x50\x4b\x01\x02";
    private const LOCAL_SIGNATURE = "\x50\x4b\x03\x04";

    private const METHOD_STORED = 0;
    private const METHOD_DEFLATE = 8;

    /** The largest single entry this reader will inflate, in bytes. */
    public const MAX_ENTRY_BYTES = 64 * 1024 * 1024;

    public const MAX_TOTAL_BYTES = 128 * 1024 * 1024;

    /** The largest number of entries it will walk. */
    public const MAX_ENTRIES = 4096;

    private function __construct()
    {
    }

    /**
     * Every file entry in the archive, keyed by its name, in central directory
     * order.
     *
     * @return array<string, string>
     *
     * @throws \RuntimeException on anything this reader will not read
     */
    public static function entries(string $bytes): array
    {
        $eocd = strrpos($bytes, self::EOCD_SIGNATURE);

        if ($eocd === false) {
            throw new \RuntimeException(
                'This file is not a zip archive: it carries no end of central directory record. A OneRoster'
                    . ' package is a flat zip of CSV files.',
            );
        }

        $count = self::short($bytes, $eocd + 10);
        $offset = self::long($bytes, $eocd + 16);

        if ($count === 0xFFFF || $offset === 0xFFFFFFFF) {
            throw new \RuntimeException(
                'This archive is Zip64. This reader reads the 32-bit format that every roster exporter'
                    . ' produces; a Zip64 package has to be repacked before it can be read here.',
            );
        }

        if ($count > self::MAX_ENTRIES) {
            throw new \RuntimeException(sprintf(
                'This archive declares %d entries and this reader walks at most %d. A OneRoster package is'
                    . ' eight files.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $count,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::MAX_ENTRIES,
            ));
        }

        $entries = [];
        $totalBytes = 0;

        for ($index = 0; $index < $count; $index++) {
            if (substr($bytes, $offset, 4) !== self::CENTRAL_SIGNATURE) {
                throw new \RuntimeException(
                    'This archive\'s central directory ends earlier than it says it does, so it is truncated'
                        . ' or was rewritten by something that did not finish.',
                );
            }

            $method = self::short($bytes, $offset + 10);
            $compressed = self::long($bytes, $offset + 20);
            $uncompressed = self::long($bytes, $offset + 24);
            $nameLength = self::short($bytes, $offset + 28);
            $extraLength = self::short($bytes, $offset + 30);
            $commentLength = self::short($bytes, $offset + 32);
            $localOffset = self::long($bytes, $offset + 42);
            $name = substr($bytes, $offset + 46, $nameLength);
            $offset += 46 + $nameLength + $extraLength + $commentLength;

            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }

            self::guardName($name);

            if ($uncompressed > self::MAX_ENTRY_BYTES) {
                throw new \RuntimeException(sprintf(
                    'The entry "%s" declares %d uncompressed bytes and this reader inflates at most %d.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $name,
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $uncompressed,
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    self::MAX_ENTRY_BYTES,
                ));
            }

            $totalBytes += $uncompressed;
            if ($totalBytes > self::MAX_TOTAL_BYTES) {
                throw new \RuntimeException('The archive exceeds the total extracted size limit.');
            }
            if (isset($entries[$name])) {
                throw new \RuntimeException('The archive contains duplicate entry names.');
            }
            $entries[$name] = self::inflate($bytes, $localOffset, $method, $compressed, $uncompressed, $name);
        }

        return $entries;
    }

    private static function inflate(
        string $bytes,
        int $localOffset,
        int $method,
        int $compressed,
        int $uncompressed,
        string $name,
    ): string {
        if (substr($bytes, $localOffset, 4) !== self::LOCAL_SIGNATURE) {
            throw new \RuntimeException(sprintf(
                'The entry "%s" points at an offset that is not a local file header, so this archive\'s'
                    . ' central directory and its data disagree.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $name,
            ));
        }

        $nameLength = self::short($bytes, $localOffset + 26);
        $extraLength = self::short($bytes, $localOffset + 28);
        $data = substr($bytes, $localOffset + 30 + $nameLength + $extraLength, $compressed);

        if (strlen($data) !== $compressed) {
            throw new \RuntimeException('The archive contains a truncated entry.');
        }

        if ($method === self::METHOD_STORED) {
            if ($compressed !== $uncompressed) {
                throw new \RuntimeException('The stored entry size does not match its declared size.');
            }
            return $data;
        }

        if ($method !== self::METHOD_DEFLATE) {
            throw new \RuntimeException(sprintf(
                'The entry "%s" uses compression method %d. This reader reads stored and DEFLATE entries,'
                    . ' which is what every exporter writes.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $name,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $method,
            ));
        }

        // Never let a forged central-directory size trigger unbounded inflation.
        $inflated = @gzinflate($data, max(1, $uncompressed));

        if ($inflated === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \RuntimeException(sprintf('The entry "%s" could not be decompressed.', $name));
        }

        if (strlen($inflated) !== $uncompressed) {
            throw new \RuntimeException('The extracted entry size does not match its declared size.');
        }

        return $inflated;
    }

    private static function guardName(string $name): void
    {
        $normalised = str_replace('\\', '/', $name);

        if (str_starts_with($normalised, '/') || preg_match('#(^|/)\.\.(/|$)#', $normalised) === 1) {
            throw new \RuntimeException(sprintf(
                'The entry "%s" carries an absolute path or a parent traversal. A roster package is a flat'
                    . ' set of CSV files and a package that is not one is not read.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $name,
            ));
        }
    }

    private static function short(string $bytes, int $at): int
    {
        $value = unpack('v', substr($bytes, $at, 2));

        return \is_array($value) ? (int) $value[1] : 0;
    }

    private static function long(string $bytes, int $at): int
    {
        $value = unpack('V', substr($bytes, $at, 4));

        return \is_array($value) ? (int) $value[1] : 0;
    }
}
