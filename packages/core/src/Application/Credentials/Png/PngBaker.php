<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials\Png;

/**
 * Open Badges 2.0 baking: the assertion is carried inside the PNG itself, in
 * an uncompressed iTXt chunk keyed "openbadges", and a verifier reads it out
 * of the image a holder shared.
 *
 * Baking is what makes a free site's badge genuinely portable rather than a
 * JSON document nothing consumes, and it needs no key generation, storage or
 * rotation, which is why this format is free while the signed ones are not.
 * Open Badges 3.0 baking (an iTXt chunk keyed "openbadgecredential" holding a
 * compact JWS, or an element inside an SVG) arrives with signing and not
 * before, and a JPEG or a PDF cannot be baked at all.
 *
 * The chunk is written uncompressed on purpose: the specification says so,
 * and a compressed chunk is one a strict reader is entitled to reject.
 */
final class PngBaker
{
    private const SIGNATURE = "\x89PNG\r\n\x1a\n";
    public const KEYWORD = 'openbadges';

    /**
     * Replaces any existing openbadges chunk and inserts the new one directly
     * before IEND, which is where a reader looks for it.
     */
    public function bake(string $png, string $payload): string
    {
        if (!str_starts_with($png, self::SIGNATURE)) {
            throw new \InvalidArgumentException('Only a PNG can carry a baked Open Badges assertion.');
        }

        $output = self::SIGNATURE;
        $offset = \strlen(self::SIGNATURE);
        $length = \strlen($png);

        while ($offset + 8 <= $length) {
            $size = $this->readUint32($png, $offset);
            $type = substr($png, $offset + 4, 4);
            $chunk = substr($png, $offset, 12 + $size);
            $offset += 12 + $size;

            if ($type === 'IEND') {
                $output .= $this->chunk('iTXt', $this->itxt($payload));
                $output .= $chunk;

                return $output;
            }

            if ($type === 'iTXt' && str_starts_with(substr($chunk, 8), self::KEYWORD . "\0")) {
                // A rebake replaces rather than appends: two openbadges chunks
                // in one file is a badge that verifies differently depending
                // on which one a reader finds first.
                continue;
            }

            $output .= $chunk;
        }

        throw new \InvalidArgumentException('The PNG has no IEND chunk and cannot be baked.');
    }

    /**
     * What a verifier finds inside a badge image, or null when there is
     * nothing baked in.
     */
    public function extract(string $png): ?string
    {
        if (!str_starts_with($png, self::SIGNATURE)) {
            return null;
        }

        $offset = \strlen(self::SIGNATURE);
        $length = \strlen($png);

        while ($offset + 8 <= $length) {
            $size = $this->readUint32($png, $offset);
            $type = substr($png, $offset + 4, 4);
            $data = substr($png, $offset + 8, $size);
            $offset += 12 + $size;

            if ($type === 'iTXt' && str_starts_with($data, self::KEYWORD . "\0")) {
                // keyword NUL, compression flag, compression method, language
                // NUL, translated keyword NUL, then the text.
                $rest = substr($data, \strlen(self::KEYWORD) + 3);
                $parts = explode("\0", $rest, 3);

                return $parts[2] ?? null;
            }

            if ($type === 'IEND') {
                return null;
            }
        }

        return null;
    }

    /**
     * A plain canvas to bake into when the site has configured no badge
     * image. Solid colour, 8-bit truecolour, which every reader handles and
     * which needs no image extension: the core takes no dependency beyond PSR
     * interfaces, so GD is not available to it.
     */
    public function canvas(int $width = 256, int $height = 256, string $hexColour = '#24405c'): string
    {
        [$r, $g, $b] = $this->rgb($hexColour);

        $raw = '';
        $row = str_repeat(\chr($r) . \chr($g) . \chr($b), $width);

        for ($y = 0; $y < $height; $y++) {
            $raw .= "\0" . $row;
        }

        $ihdr = pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        $compressed = gzcompress($raw, 9);

        if ($compressed === false) {
            throw new \RuntimeException('The badge canvas could not be compressed.');
        }

        return self::SIGNATURE
            . $this->chunk('IHDR', $ihdr)
            . $this->chunk('IDAT', $compressed)
            . $this->chunk('IEND', '');
    }

    private function itxt(string $payload): string
    {
        return self::KEYWORD . "\0" . "\0" . "\0" . "\0" . "\0" . $payload;
    }

    private function chunk(string $type, string $data): string
    {
        return pack('N', \strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    private function readUint32(string $bytes, int $offset): int
    {
        $unpacked = unpack('N', substr($bytes, $offset, 4));

        return $unpacked === false ? 0 : (int) $unpacked[1];
    }

    /**
     * @return array{int, int, int}
     */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (\strlen($hex) !== 6 || preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return [36, 64, 92];
        }

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
