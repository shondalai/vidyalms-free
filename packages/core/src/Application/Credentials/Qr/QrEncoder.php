<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials\Qr;

/**
 * A QR encoder, written as core code because only PSR interfaces are
 * permitted as external code under src/.
 *
 * The parameters are fixed here so the work is bounded and so nobody has to
 * decide them again: model 2, byte mode, error correction level M, versions 1
 * to 10, SVG output. Level M is the standard trade for a printed artefact,
 * and ten versions carry 213 bytes, which is more than any verification URL
 * this product emits. A payload above that is refused rather than silently
 * truncated, because a QR code that scans to half a URL is worse than none.
 *
 * The accessibility rule that goes with it is enforced elsewhere and stated
 * here so the two are read together: the verification URL is also emitted as
 * real selectable text, so the QR is a convenience and never the only route.
 */
final class QrEncoder
{
    public const MAX_VERSION = 10;

    /**
     * Data codewords, then error correction codewords per block, then the
     * block split, per version at level M. The numbers are the specification's
     * and are written out rather than derived, because deriving them is how a
     * subtle capacity bug reaches paper.
     *
     * [data codewords, ec codewords per block, group 1 blocks, group 1 data,
     * group 2 blocks, group 2 data]
     *
     * @var array<int, array{int, int, int, int, int, int}>
     */
    private const LEVEL_M = [
        1 => [16, 10, 1, 16, 0, 0],
        2 => [28, 16, 1, 28, 0, 0],
        3 => [44, 26, 1, 44, 0, 0],
        4 => [64, 18, 2, 32, 0, 0],
        5 => [86, 24, 2, 43, 0, 0],
        6 => [108, 16, 4, 27, 0, 0],
        7 => [124, 18, 4, 31, 0, 0],
        8 => [154, 22, 2, 38, 2, 39],
        9 => [182, 22, 3, 36, 2, 37],
        10 => [216, 26, 4, 43, 1, 44],
    ];

    /**
     * Alignment pattern centres per version. Version 1 has none.
     *
     * @var array<int, list<int>>
     */
    private const ALIGNMENT = [
        1 => [],
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
        8 => [6, 24, 42],
        9 => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** Remainder bits appended after the interleaved codewords, per version. */
    private const REMAINDER_BITS = [
        1 => 0, 2 => 7, 3 => 7, 4 => 7, 5 => 7, 6 => 7, 7 => 0, 8 => 0, 9 => 0, 10 => 0,
    ];

    public function __construct(private readonly GaloisField $field = new GaloisField())
    {
    }

    /**
     * The module matrix, true meaning dark. Row major, origin at the top left.
     *
     * @return list<list<bool>>
     */
    public function encode(string $text): array
    {
        $version = $this->versionFor($text);
        $codewords = $this->codewords($text, $version);
        $size = 17 + (4 * $version);

        $reserved = $this->reservedMatrix($version, $size);
        $best = null;
        $bestPenalty = null;

        for ($mask = 0; $mask < 8; $mask++) {
            $matrix = $this->place($codewords, $reserved, $version, $size, $mask);
            $penalty = $this->penalty($matrix, $size);

            if ($bestPenalty === null || $penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best = $matrix;
            }
        }

        /** @var list<list<bool>> $best */
        return $best;
    }

    /**
     * The code as an SVG document.
     *
     * The alt text is the caller's, not this class's: an image on an
     * evidentiary page with no alternative fails SC 1.1.1, and the element
     * that draws this carries a required alt for exactly that reason.
     */
    public function toSvg(string $text, int $sizePx = 160, string $title = ''): string
    {
        $matrix = $this->encode($text);
        $modules = \count($matrix);
        $quiet = 4;
        $total = $modules + (2 * $quiet);

        $paths = [];

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $paths[] = 'M' . ($x + $quiet) . ' ' . ($y + $quiet) . 'h1v1h-1z';
                }
            }
        }

        $titleMarkup = $title === ''
            ? ''
            : '<title>' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '"'
            . ' width="' . $sizePx . '" height="' . $sizePx . '" role="img" shape-rendering="crispEdges">'
            . $titleMarkup
            . '<rect width="' . $total . '" height="' . $total . '" fill="#ffffff"/>'
            . '<path fill="#000000" d="' . implode('', $paths) . '"/>'
            . '</svg>';
    }

    private function versionFor(string $text): int
    {
        $length = \strlen($text);

        for ($version = 1; $version <= self::MAX_VERSION; $version++) {
            [$dataCodewords] = self::LEVEL_M[$version];
            $headerBits = 4 + ($version >= 10 ? 16 : 8);
            $capacity = intdiv(($dataCodewords * 8) - $headerBits, 8);

            if ($length <= $capacity) {
                return $version;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'A QR payload of %d bytes exceeds version %d at error correction level M.',
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $length,
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            self::MAX_VERSION,
        ));
    }

    /**
     * Data plus error correction, interleaved, as a bit string.
     */
    private function codewords(string $text, int $version): string
    {
        [$dataCodewords, $ecPerBlock, $group1Blocks, $group1Data, $group2Blocks, $group2Data] = self::LEVEL_M[$version];

        $bits = '0100';
        $bits .= str_pad(decbin(\strlen($text)), $version >= 10 ? 16 : 8, '0', STR_PAD_LEFT);

        for ($i = 0, $length = \strlen($text); $i < $length; $i++) {
            $bits .= str_pad(decbin(\ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }

        $capacityBits = $dataCodewords * 8;
        $bits .= str_repeat('0', min(4, $capacityBits - \strlen($bits)));

        if (\strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (\strlen($bits) % 8));
        }

        $pad = ['11101100', '00010001'];
        $index = 0;

        while (\strlen($bits) < $capacityBits) {
            $bits .= $pad[$index % 2];
            $index++;
        }

        $data = [];

        for ($i = 0; $i < $capacityBits; $i += 8) {
            $data[] = (int) bindec(substr($bits, $i, 8));
        }

        $blocks = [];
        $offset = 0;

        for ($i = 0; $i < $group1Blocks; $i++) {
            $blocks[] = \array_slice($data, $offset, $group1Data);
            $offset += $group1Data;
        }

        for ($i = 0; $i < $group2Blocks; $i++) {
            $blocks[] = \array_slice($data, $offset, $group2Data);
            $offset += $group2Data;
        }

        $ecBlocks = [];

        foreach ($blocks as $block) {
            /** @var list<int> $block */
            $ecBlocks[] = $this->field->remainder($block, $ecPerBlock);
        }

        $interleaved = '';
        $longest = max(array_map('\count', $blocks));

        for ($i = 0; $i < $longest; $i++) {
            foreach ($blocks as $block) {
                if (isset($block[$i])) {
                    $interleaved .= str_pad(decbin($block[$i]), 8, '0', STR_PAD_LEFT);
                }
            }
        }

        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                $interleaved .= str_pad(decbin($block[$i]), 8, '0', STR_PAD_LEFT);
            }
        }

        return $interleaved . str_repeat('0', self::REMAINDER_BITS[$version]);
    }

    /**
     * The function patterns, drawn once and shared by every mask trial: a mask
     * changes only the data modules.
     *
     * @return array{matrix: array<int, array<int, ?bool>>, reserved: array<int, array<int, bool>>}
     */
    private function reservedMatrix(int $version, int $size): array
    {
        /** @var array<int, array<int, ?bool>> $matrix */
        $matrix = array_fill(0, $size, array_fill(0, $size, null));
        /** @var array<int, array<int, bool>> $reserved */
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$x, $y]) {
            for ($dy = -1; $dy <= 7; $dy++) {
                for ($dx = -1; $dx <= 7; $dx++) {
                    $px = $x + $dx;
                    $py = $y + $dy;

                    if ($px < 0 || $py < 0 || $px >= $size || $py >= $size) {
                        continue;
                    }

                    $inRing = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6
                        && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6);
                    $inCore = $dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4;

                    $matrix[$py][$px] = $inRing || $inCore;
                    $reserved[$py][$px] = true;
                }
            }
        }

        for ($i = 8; $i < $size - 8; $i++) {
            $dark = $i % 2 === 0;
            $matrix[6][$i] = $dark;
            $reserved[6][$i] = true;
            $matrix[$i][6] = $dark;
            $reserved[$i][6] = true;
        }

        $last = $size - 7;

        foreach (self::ALIGNMENT[$version] as $cy) {
            foreach (self::ALIGNMENT[$version] as $cx) {
                // The three centres that coincide with a finder pattern are
                // the only ones omitted. Testing the reserved map instead
                // would also drop the centres that merely cross the timing
                // patterns, which the specification does draw, and a version
                // seven code would then be unreadable.
                $onFinder = ($cx === 6 && $cy === 6)
                    || ($cx === 6 && $cy === $last)
                    || ($cx === $last && $cy === 6);

                if ($onFinder) {
                    continue;
                }

                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $matrix[$cy + $dy][$cx + $dx] = abs($dx) === 2 || abs($dy) === 2
                            || ($dx === 0 && $dy === 0);
                        $reserved[$cy + $dy][$cx + $dx] = true;
                    }
                }
            }
        }

        // The dark module, which is always set and is always reserved.
        $matrix[$size - 8][8] = true;
        $reserved[$size - 8][8] = true;

        foreach ($this->formatPositions($size) as [$first, $second]) {
            $reserved[$first[0]][$first[1]] = true;
            $reserved[$second[0]][$second[1]] = true;
        }

        if ($version >= 7) {
            $info = $this->versionInformation($version);

            for ($i = 0; $i < 18; $i++) {
                $bit = (($info >> $i) & 1) === 1;
                $row = intdiv($i, 3);
                $col = $i % 3;
                $matrix[$row][$size - 11 + $col] = $bit;
                $reserved[$row][$size - 11 + $col] = true;
                $matrix[$size - 11 + $col][$row] = $bit;
                $reserved[$size - 11 + $col][$row] = true;
            }
        }

        return ['matrix' => $matrix, 'reserved' => $reserved];
    }

    /**
     * Where the fifteen format bits land, both copies, indexed by bit number
     * from the least significant. Each entry is [row, column] for copy one
     * and then copy two.
     *
     * @return list<array{array{int, int}, array{int, int}}>
     */
    private function formatPositions(int $size): array
    {
        $positions = [];

        for ($i = 0; $i <= 14; $i++) {
            $first = match (true) {
                $i <= 5 => [$i, 8],
                $i === 6 => [7, 8],
                $i === 7 => [8, 8],
                $i === 8 => [8, 7],
                default => [8, 14 - $i],
            };

            // Bits zero to seven run across the top-right copy; bits eight to
            // fourteen run down the bottom-left copy. The fixed dark module
            // at [size - 8, 8] lies between those two runs.
            $second = $i <= 7 ? [8, $size - 1 - $i] : [$size - 15 + $i, 8];

            $positions[] = [$first, $second];
        }

        return $positions;
    }

    /**
     * @param array{matrix: array<int, array<int, ?bool>>, reserved: array<int, array<int, bool>>} $base
     *
     * @return list<list<bool>>
     */
    private function place(string $bits, array $base, int $version, int $size, int $mask): array
    {
        $matrix = $base['matrix'];
        $reserved = $base['reserved'];
        $index = 0;
        $length = \strlen($bits);
        $upward = true;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }

            for ($step = 0; $step < $size; $step++) {
                $y = $upward ? $size - 1 - $step : $step;

                foreach ([$right, $right - 1] as $x) {
                    if ($reserved[$y][$x]) {
                        continue;
                    }

                    $bit = $index < $length && $bits[$index] === '1';
                    $index++;

                    $matrix[$y][$x] = $bit !== $this->maskAt($mask, $x, $y);
                }
            }

            $upward = !$upward;
        }

        $format = $this->formatInformation($mask);

        foreach ($this->formatPositions($size) as $i => [$first, $second]) {
            $bit = (($format >> $i) & 1) === 1;
            $matrix[$first[0]][$first[1]] = $bit;
            $matrix[$second[0]][$second[1]] = $bit;
        }

        $matrix[$size - 8][8] = true;

        $final = [];

        foreach ($matrix as $row) {
            $final[] = array_values(array_map(static fn (?bool $cell): bool => $cell === true, $row));
        }

        return $final;
    }

    private function maskAt(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($y + $x) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($y + $x) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => (($y * $x) % 2) + (($y * $x) % 3) === 0,
            6 => ((($y * $x) % 2) + (($y * $x) % 3)) % 2 === 0,
            default => ((($y + $x) % 2) + (($y * $x) % 3)) % 2 === 0,
        };
    }

    /**
     * BCH(15,5) over the level M indicator, then the specification's mask
     * pattern, which is what stops an all-zero format from reading as valid.
     */
    private function formatInformation(int $mask): int
    {
        $data = $mask;
        $value = $data << 10;

        for ($i = 4; $i >= 0; $i--) {
            if ((($value >> ($i + 10)) & 1) === 1) {
                $value ^= 0x537 << $i;
            }
        }

        return (($data << 10) | $value) ^ 0x5412;
    }

    private function versionInformation(int $version): int
    {
        $value = $version << 12;

        for ($i = 5; $i >= 0; $i--) {
            if ((($value >> ($i + 12)) & 1) === 1) {
                $value ^= 0x1F25 << $i;
            }
        }

        return ($version << 12) | $value;
    }

    /**
     * The four penalty rules, which is how the mask is chosen. A lower score
     * is a code a scanner finds faster.
     *
     * @param list<list<bool>> $matrix
     */
    private function penalty(array $matrix, int $size): int
    {
        $score = 0;

        for ($y = 0; $y < $size; $y++) {
            $score += $this->runPenalty($matrix[$y]);
        }

        for ($x = 0; $x < $size; $x++) {
            $column = [];

            for ($y = 0; $y < $size; $y++) {
                $column[] = $matrix[$y][$x];
            }

            $score += $this->runPenalty($column);
        }

        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $first = $matrix[$y][$x];

                if (
                    $matrix[$y][$x + 1] === $first
                    && $matrix[$y + 1][$x] === $first
                    && $matrix[$y + 1][$x + 1] === $first
                ) {
                    $score += 3;
                }
            }
        }

        $dark = 0;

        foreach ($matrix as $row) {
            foreach ($row as $cell) {
                if ($cell) {
                    $dark++;
                }
            }
        }

        $percent = (int) floor(($dark * 100) / ($size * $size));
        $score += 10 * (int) floor(abs($percent - 50) / 5);

        return $score;
    }

    /**
     * @param list<bool> $line
     */
    private function runPenalty(array $line): int
    {
        $score = 0;
        $run = 1;

        for ($i = 1, $length = \count($line); $i < $length; $i++) {
            if ($line[$i] === $line[$i - 1]) {
                $run++;
                continue;
            }

            if ($run >= 5) {
                $score += 3 + ($run - 5);
            }

            $run = 1;
        }

        if ($run >= 5) {
            $score += 3 + ($run - 5);
        }

        return $score;
    }
}
