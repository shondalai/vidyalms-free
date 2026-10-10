<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Credentials;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Credentials\Qr\GaloisField;
use Shondalai\VidyaLMS\Core\Application\Credentials\Qr\QrEncoder;

/**
 * The QR encoder is core code because only PSR interfaces are permitted as
 * external code under src/, which means the arithmetic is ours and has to be
 * checked against the specification's own worked example rather than trusted.
 */
final class QrEncoderTest extends TestCase
{
    public function testTheErrorCorrectionMatchesThePublishedWorkedExample(): void
    {
        // The version 1-M example every implementation is checked against:
        // the numeric encoding of "01234567" and its ten published error
        // correction codewords.
        $data = [16, 32, 12, 86, 97, 128, 236, 17, 236, 17, 236, 17, 236, 17, 236, 17];
        $expected = [165, 36, 212, 193, 237, 54, 199, 135, 44, 85];

        self::assertSame($expected, (new GaloisField())->remainder($data, 10));
    }

    public function testTheMatrixCarriesTheThreeFindersTheTimingPatternsAndTheDarkModule(): void
    {
        $matrix = (new QrEncoder())->encode('https://example.test/verify/ABCD2345EFGH');
        $size = \count($matrix);

        self::assertSame(0, ($size - 17) % 4);

        foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$top, $left]) {
            self::assertTrue($matrix[$top][$left]);
            self::assertTrue($matrix[$top][$left + 6]);
            self::assertTrue($matrix[$top + 6][$left]);
            self::assertFalse($matrix[$top + 1][$left + 1]);
            self::assertTrue($matrix[$top + 3][$left + 3]);
        }

        for ($i = 8; $i < $size - 8; $i++) {
            self::assertSame($i % 2 === 0, $matrix[6][$i]);
            self::assertSame($i % 2 === 0, $matrix[$i][6]);
        }

        self::assertTrue($matrix[$size - 8][8]);
    }

    public function testBothFormatCopiesMatchAPublishedLevelMCodeword(): void
    {
        $matrix = (new QrEncoder())->encode('SHORT');
        $size = \count($matrix);
        $first = 0;
        $second = 0;

        // Independent Model 2 coordinates, expressed as [row, column]. The
        // encoder's private coordinate map is deliberately not reused here.
        $firstPositions = [
            [0, 8], [1, 8], [2, 8], [3, 8], [4, 8], [5, 8], [7, 8], [8, 8],
            [8, 7], [8, 5], [8, 4], [8, 3], [8, 2], [8, 1], [8, 0],
        ];
        $secondPositions = [];

        for ($i = 0; $i < 8; $i++) {
            $secondPositions[] = [8, $size - 1 - $i];
        }

        for ($i = 8; $i < 15; $i++) {
            $secondPositions[] = [$size - 15 + $i, 8];
        }

        foreach ($firstPositions as $i => [$row, $column]) {
            if ($matrix[$row][$column]) {
                $first |= 1 << $i;
            }
        }

        foreach ($secondPositions as $i => [$row, $column]) {
            if ($matrix[$row][$column]) {
                $second |= 1 << $i;
            }
        }

        // JIS X 0510 Appendix C's masked BCH(15,5) words for level M and
        // masks zero through seven. This catches a valid word placed with
        // reversed axes or bit order, even if both copies repeat that error.
        $levelMFormatWords = [
            0x5412, 0x5125, 0x5E7C, 0x5B4B,
            0x45F9, 0x40CE, 0x4F97, 0x4AA0,
        ];

        self::assertTrue(\in_array($first, $levelMFormatWords, true));
        self::assertSame($first, $second);
    }

    public function testTheVersionGrowsWithThePayloadAndStopsAtTen(): void
    {
        $encoder = new QrEncoder();

        self::assertCount(21, $encoder->encode('SHORT'));
        self::assertCount(57, $encoder->encode(str_repeat('C', 213)));
    }

    public function testAPayloadTooLargeIsRefusedRatherThanTruncated(): void
    {
        // A QR code that scans to half a URL is worse than none at all.
        $this->expectException(\InvalidArgumentException::class);

        (new QrEncoder())->encode(str_repeat('x', 400));
    }

    public function testTheSvgCarriesATitleAndAQuietZone(): void
    {
        $svg = (new QrEncoder())->toSvg('https://example.test/verify/ABCD2345EFGH', 160, 'Verify this certificate');

        self::assertStringStartsWith('<svg', $svg);
        self::assertStringContainsString('role="img"', $svg);
        self::assertStringContainsString('<title>Verify this certificate</title>', $svg);
        // A version 3 symbol is 29 modules, plus the four of quiet zone on
        // each side that a scanner needs to find the symbol at all.
        self::assertStringContainsString('viewBox="0 0 37 37"', $svg);
    }
}
