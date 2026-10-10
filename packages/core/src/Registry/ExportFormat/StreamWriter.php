<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ExportFormat;

/**
 * Where an export writer puts its bytes.
 *
 * It is an object rather than a PHP stream resource because the core has no
 * filesystem: an artefact goes to {@see \Shondalai\VidyaLMS\Core\Port\Storage\StoragePort}
 * and the adapter decides where that is. The byte ceiling bounds the buffer
 * and the final joined string, including for scheduled exports.
 */
final class StreamWriter
{
    private string $buffer = '';

    private int $bytes = 0;

    /** @var list<string> */
    private array $chunks = [];

    /**
     * @param positive-int $flushEvery how many bytes accumulate before a chunk
     *                                 is sealed, so a long export holds one
     *                                 chunk rather than one string
     */
    public function __construct(
        private readonly int $flushEvery = 262144,
        private readonly int $maxBytes = 16777216,
    )
    {
    }

    public function write(string $bytes): void
    {
        if (\strlen($bytes) > $this->maxBytes - $this->bytes) {
            throw new \RuntimeException('The export exceeds the file-size limit. Narrow the report filters.');
        }
        $this->buffer .= $bytes;
        $this->bytes += \strlen($bytes);

        if (\strlen($this->buffer) >= $this->flushEvery) {
            $this->chunks[] = $this->buffer;
            $this->buffer = '';
        }
    }

    /** Everything written so far, in order. */
    public function contents(): string
    {
        return implode('', $this->chunks) . $this->buffer;
    }

    public function byteSize(): int
    {
        return $this->bytes;
    }
}
