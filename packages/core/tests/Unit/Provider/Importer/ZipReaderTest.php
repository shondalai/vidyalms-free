<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\Importer;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Infrastructure\Archive\ZipReader;

final class ZipReaderTest extends TestCase
{
    public function testReadsStoredAndDeflatedEntries(): void
    {
        foreach ([0, 8] as $method) {
            self::assertSame(['users.csv' => 'hello'], ZipReader::entries($this->archive('hello', 5, $method)));
        }
    }

    public function testFalseDeclaredSizeCannotAllocateAnUnboundedInflatedEntry(): void
    {
        $this->expectException(\RuntimeException::class);
        ZipReader::entries($this->archive(str_repeat('x', 1024 * 1024), 16, 8));
    }

    public function testStoredEntryCannotUnderstateItsSize(): void
    {
        $this->expectException(\RuntimeException::class);
        ZipReader::entries($this->archive(str_repeat('x', 1024), 16, 0));
    }

    private function archive(string $body, int $declared, int $method): string
    {
        $name = 'users.csv';
        $data = $method === 8 ? gzdeflate($body) : $body;
        if ($data === false) {
            throw new \RuntimeException('Cannot build compressed fixture.');
        }
        $local = "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, $method, 0, 0,
            crc32($body), strlen($data), $declared, strlen($name), 0) . $name . $data;
        $central = "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, $method, 0, 0,
            crc32($body), strlen($data), $declared, strlen($name), 0, 0, 0, 0, 0, 0) . $name;
        return $local . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, 1, 1, strlen($central), strlen($local), 0);
    }
}
