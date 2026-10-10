<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

/**
 * Writes ustar archives in memory so fixtures are built by code rather than
 * committed as binaries. It writes whatever name it is handed, including the
 * hostile ones, which is the point: the archive safety tests need an archive
 * a well behaved writer would refuse to produce.
 */
final class TarWriter
{
    private const BLOCK = 512;
    private const SIZE_FIELD = 12;

    private function __construct()
    {
    }

    /**
     * @param array<string, string> $entries path to contents
     */
    public static function tar(array $entries): string
    {
        $out = '';

        foreach ($entries as $name => $contents) {
            $out .= self::header($name, \strlen($contents));
            $out .= $contents . str_repeat("\0", (self::BLOCK - \strlen($contents) % self::BLOCK) % self::BLOCK);
        }

        return $out . str_repeat("\0", self::BLOCK * 2);
    }

    /**
     * @param array<string, string> $entries
     */
    public static function gzip(array $entries): string
    {
        return (string) gzencode(self::tar($entries), 6);
    }

    /**
     * Any tar bytes, gzipped, for the shapes self::tar() will not produce.
     */
    public static function compress(string $tar): string
    {
        return (string) gzencode($tar, 6);
    }

    /**
     * One entry whose twelve byte size field is written verbatim, with a
     * checksum that matches it. It is how the size shapes no honest writer
     * produces are built: GNU's base 256 form, a negative size, a size larger
     * than the archive holding it.
     */
    public static function tarWithRawSize(string $name, string $sizeField, string $contents = ''): string
    {
        if (\strlen($sizeField) !== self::SIZE_FIELD) {
            throw new \InvalidArgumentException(sprintf('A tar size field is %d bytes.', self::SIZE_FIELD));
        }

        $header = self::checksummed(substr_replace(self::raw($name, 0), $sizeField, 124, self::SIZE_FIELD));
        $padding = str_repeat("\0", (self::BLOCK - \strlen($contents) % self::BLOCK) % self::BLOCK);

        return $header . $contents . $padding . str_repeat("\0", self::BLOCK * 2);
    }

    /**
     * The base 256 encoding of a size, two's complement and big endian, with
     * the flag bit GNU sets on the first byte. Negative values are what a
     * crafted archive uses to walk a reader backwards.
     */
    public static function base256Size(int $size): string
    {
        $field = '';
        $value = $size;

        for ($position = 0; $position < self::SIZE_FIELD; $position++) {
            $field = \chr($value & 0xFF) . $field;
            $value >>= 8;
        }

        return \chr(\ord($field[0]) | 0x80) . substr($field, 1);
    }

    private static function header(string $name, int $size): string
    {
        return self::checksummed(self::raw($name, $size));
    }

    /**
     * The header with its checksum field still blank, which is what the
     * checksum is computed over.
     */
    private static function raw(string $name, int $size): string
    {
        return pack(
            'a100a8a8a8a12a12A8a1a100a6a2a32a32a8a8a155a12',
            $name,
            "0000644\0",
            "0000000\0",
            "0000000\0",
            sprintf('%011o', $size) . "\0",
            sprintf('%011o', 1767225600) . "\0",
            '        ',
            '0',
            '',
            "ustar\0",
            '00',
            'moodle',
            'moodle',
            '',
            '',
            '',
            '',
        );
    }

    private static function checksummed(string $header): string
    {
        $checksum = 0;

        for ($position = 0; $position < self::BLOCK; $position++) {
            $checksum += \ord($header[$position]);
        }

        return substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
    }
}
