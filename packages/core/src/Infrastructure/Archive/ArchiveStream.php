<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Archive;

/**
 * A forward-only reader over the bytes of an archive, decompressing as it is
 * read rather than in one piece.
 *
 * It exists because the decompressed archive is the one thing about an upload
 * the site does not control. A reader that inflates the whole member into a
 * string before looking at it has already lost: the string that crosses the
 * limit is allocated before anything can refuse it, and the fatal error that
 * ends the process is not an exception any job can report. So the bytes arrive
 * a piece at a time and every allocation is refused before it is attempted.
 *
 * ## What bounds a single allocation
 *
 * One inflate call returns everything its input expands to, and deflate's
 * ceiling is about 1032 to 1, so the size of the input chunk is what bounds
 * the largest string this class ever creates in one step: a kilobyte in, about
 * a megabyte out, however hostile the archive is. The gzip trailer is not
 * consulted here at all, because it is written by whoever built the archive.
 *
 * ## Two limits, both enforced while the bytes arrive
 *
 * The first is the total: the caller's cap, lowered to half of what
 * memory_limit still leaves once a reserve is set aside, because the archive
 * is held twice at its worst moment (see COPIES_HELD). It is measured against
 * what has actually been inflated, so an archive that understates its size in
 * its trailer is stopped at the piece that crosses the cap.
 *
 * The second is the live one. Materialising an entry holds the pieces and the
 * finished string at the same time, so the room a read needs is twice what it
 * returns; requireRoom() answers that question against memory_get_usage()
 * rather than against an estimate made when the file was opened, which is what
 * makes it survive whatever else the process allocated in the meantime.
 */
final class ArchiveStream
{
    /**
     * Compressed bytes handed to the inflater at a time. Small on purpose:
     * see the note on the deflate ceiling above. A quarter of a kilobyte in
     * bounds one call's output at about a quarter of a megabyte, which keeps
     * what this reader holds of its own negligible beside the cap it is
     * enforcing.
     */
    private const INPUT_CHUNK = 256;

    /** Uncompressed bytes taken from the source at a time. */
    private const PLAIN_CHUNK = 65536;

    /**
     * How many times over the archive is held at its worst moment, which is
     * what the cap divides the available memory by.
     *
     * The reader that owns this stream keeps every entry it has read, so the
     * decompressed archive is held once. The entry being assembled is held a
     * second time while it grows: take() appends a piece to a string it
     * already holds, and the append needs room for the longer string before
     * the shorter one is released. In the worst case the archive is one
     * entry, so both copies are the whole archive. Nothing in this class
     * needs a third.
     *
     * It is deliberately not more than that. A divisor picked for safety
     * rather than for a reason refuses backups the process demonstrably has
     * room for, and a customer whose 40 MB course will not import on a 512 MB
     * process has been failed just as surely as one whose import was killed
     * by an OOM. The live check in requireRoom() is what enforces the same
     * arithmetic against the memory the process actually has at each step;
     * this is that arithmetic made up front, so an archive nothing could hold
     * is refused before a byte of it is inflated.
     */
    private const COPIES_HELD = 2;

    /**
     * Bytes left untouched for everything else the process still has to do:
     * the tar headers this reader walks between entries, the job row and its
     * log, the request that is carrying all of it. It is taken off the top
     * before the division rather than after it, so it is left over whichever
     * of the two copies is being held.
     */
    private const RESERVE = 4194304;

    private ?\InflateContext $context = null;

    private string $buffer = '';

    private int $cursor = 0;

    private int $consumed = 0;

    private int $produced = 0;

    private bool $ended = false;

    private function __construct(
        private readonly string $raw,
        private readonly bool $compressed,
        private readonly int $cap,
        private readonly int $maxBytes,
    ) {
    }

    /**
     * @param bool $compressed whether the bytes are a gzip member
     * @param int  $maxBytes   the largest decompressed archive the caller accepts
     */
    public static function over(string $raw, bool $compressed, int $maxBytes): self
    {
        $stream = new self($raw, $compressed, self::cap($maxBytes), $maxBytes);

        if (!$compressed && \strlen($raw) > $stream->cap) {
            throw $stream->tooLarge();
        }

        if ($compressed) {
            $context = inflate_init(ZLIB_ENCODING_GZIP);

            if ($context === false) {
                throw new \RuntimeException('The gzip stream could not be opened.');
            }

            $stream->context = $context;
        }

        return $stream;
    }

    /**
     * Exactly $length bytes, or fewer only when the archive ends first, which
     * is how a header that claims more than the archive holds is caught.
     */
    public function take(int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $out = '';

        while (\strlen($out) < $length) {
            if ($this->cursor >= \strlen($this->buffer) && !$this->pull()) {
                break;
            }

            $piece = substr($this->buffer, $this->cursor, $length - \strlen($out));
            $this->cursor += \strlen($piece);

            // Before the concatenation, which holds the old string and the new
            // one at the same time.
            $this->requireRoom(\strlen($out) + \strlen($piece));
            $out .= $piece;
        }

        return $out;
    }

    /**
     * Moves past $length bytes without keeping them, and reports how many
     * there were.
     */
    public function skip(int $length): int
    {
        $skipped = 0;

        while ($skipped < $length) {
            if ($this->cursor >= \strlen($this->buffer) && !$this->pull()) {
                break;
            }

            $step = min($length - $skipped, \strlen($this->buffer) - $this->cursor);
            $this->cursor += $step;
            $skipped += $step;
        }

        return $skipped;
    }

    /**
     * Adds at least one byte to the buffer, or answers false at the end of the
     * archive.
     */
    private function pull(): bool
    {
        if ($this->cursor > 0) {
            $this->buffer = substr($this->buffer, $this->cursor);
            $this->cursor = 0;
        }

        while (!$this->ended) {
            $piece = $this->next();

            if ($piece === '') {
                continue;
            }

            $this->produced += \strlen($piece);

            // The trailer is a claim; this is a measurement, so an archive
            // that understates what it expands to buys nothing by it.
            if ($this->produced > $this->cap) {
                throw $this->tooLarge();
            }

            $this->requireRoom(\strlen($this->buffer) + \strlen($piece));
            $this->buffer .= $piece;

            return true;
        }

        return false;
    }

    /**
     * The next piece of decompressed data, or an empty string when this step
     * produced none. Ends the stream when the source runs out.
     */
    private function next(): string
    {
        if (!$this->compressed) {
            $piece = substr($this->raw, $this->consumed, self::PLAIN_CHUNK);
            $this->consumed += \strlen($piece);

            if ($piece === '') {
                $this->ended = true;
            }

            return $piece;
        }

        $context = $this->context;

        if ($context === null) {
            $this->ended = true;

            return '';
        }

        if ($this->consumed >= \strlen($this->raw)) {
            $this->ended = true;

            // Suppressed because a corrupt archive is a data problem the
            // caller reports on the job, not a PHP warning for the log.
            $tail = @inflate_add($context, '', ZLIB_FINISH);

            if ($tail === false) {
                throw new \RuntimeException('The archive ends in the middle of its gzip stream.');
            }

            return $tail;
        }

        $input = substr($this->raw, $this->consumed, self::INPUT_CHUNK);
        $this->consumed += \strlen($input);
        $piece = @inflate_add($context, $input);

        if ($piece === false) {
            throw new \RuntimeException('The archive is not readable gzip data.');
        }

        return $piece;
    }

    /**
     * The most decompressed bytes this archive may reach: the caller's cap,
     * lowered to what memory_limit still leaves after the reserve, divided by
     * the copies an assembled entry is held in. The refusal is then an
     * exception the job can report rather than a fatal allocation error
     * nothing can catch.
     */
    private static function cap(int $maxBytes): int
    {
        $limit = self::memoryLimit();

        if ($limit === null) {
            return $maxBytes;
        }

        $headroom = $limit - memory_get_usage(true) - self::RESERVE;

        return min($maxBytes, intdiv(max(0, $headroom), self::COPIES_HELD));
    }

    /**
     * Refuses an allocation this process cannot make, before it is attempted.
     */
    private function requireRoom(int $bytes): void
    {
        $limit = self::memoryLimit();

        if ($limit === null) {
            return;
        }

        $room = $limit - memory_get_usage(true) - self::RESERVE;

        if ($bytes > $room) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw self::outOfMemory($room);
        }
    }

    /**
     * memory_limit in bytes, or null when this process has no limit.
     */
    private static function memoryLimit(): ?int
    {
        $setting = trim((string) ini_get('memory_limit'));

        if ($setting === '' || $setting === '-1') {
            return null;
        }

        if (preg_match('/^(\d+)([KMG]?)$/i', $setting, $matches) !== 1) {
            return null;
        }

        $value = (int) $matches[1];

        return match (strtoupper($matches[2])) {
            'K' => $value * 1024,
            'M' => $value * 1048576,
            'G' => $value * 1073741824,
            default => $value,
        };
    }

    private function tooLarge(): \RuntimeException
    {
        if ($this->cap < $this->maxBytes) {
            return self::outOfMemory($this->cap);
        }

        return new \RuntimeException(sprintf(
            'The archive expands past the %d byte limit and was refused.',
            $this->cap,
        ));
    }

    private static function outOfMemory(int $room): \RuntimeException
    {
        return new \RuntimeException(sprintf(
            'The archive expands past the %d bytes this process has memory for and was refused.'
            . ' Raise memory_limit, or split the backup.',
            max(0, $room),
        ));
    }
}
