<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\StreamWriter;

final class StreamWriterLimitTest extends TestCase
{
    public function testAReportCannotGrowPastItsByteBudget(): void
    {
        $writer = new StreamWriter(4, 10);
        $writer->write('123456');
        $writer->write('7890');
        try {
            $writer->write('x');
            self::fail('The export exceeded its budget.');
        } catch (\RuntimeException $failure) {
            self::assertStringContainsString('file-size limit', $failure->getMessage());
        }
        self::assertSame('1234567890', $writer->contents());
        self::assertSame(10, $writer->byteSize());
    }
}
