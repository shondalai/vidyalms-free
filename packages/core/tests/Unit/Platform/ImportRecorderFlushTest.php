<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportRecorder;

/**
 * A flush rewrites the whole job log, so how often the recorder flushes
 * decides what a large migration costs: at a fixed interval, the work grows
 * with the square of the notes, and a hundred thousand note run writes several
 * gigabytes of log to say the same thing. The interval backs off instead.
 */
final class ImportRecorderFlushTest extends TestCase
{
    public function testAChattyImportDoesNotRewriteItsLogOncePerNote(): void
    {
        $flushes = 0;
        $bytes = 0;
        $recorder = new ImportRecorder(7, false, function (ImportRecorder $recorder) use (&$flushes, &$bytes): void {
            $flushes++;
            $bytes += \strlen($recorder->result()->log());
        });

        for ($note = 0; $note < 10000; $note++) {
            $recorder->note(ImportNote::INFO, sprintf('Lesson %d created.', $note));
        }

        self::assertCount(10000, $recorder->result()->notes, 'Every note is kept.');
        self::assertLessThan(
            120,
            $flushes,
            'Ten thousand notes must not be ten thousand log rewrites.',
        );
        self::assertLessThan(
            40 * \strlen($recorder->result()->log()),
            $bytes,
            'The bytes written over the run stay a small multiple of the log itself.',
        );
    }

    public function testTheFirstNotesOfAnOrdinaryImportStillFlushOneByOne(): void
    {
        $flushes = 0;
        $recorder = new ImportRecorder(7, false, static function () use (&$flushes): void {
            $flushes++;
        });

        for ($note = 0; $note < 20; $note++) {
            $recorder->note(ImportNote::INFO, 'Something happened.');
        }

        self::assertSame(20, $flushes, 'A short import is reported as it happens.');
    }

    public function testTheHeartbeatKeepsMovingOnALongRun(): void
    {
        $at = [];
        $recorder = new ImportRecorder(7, false, static function (ImportRecorder $recorder) use (&$at): void {
            $at[] = \count($recorder->result()->notes);
        });

        for ($note = 0; $note < 20000; $note++) {
            $recorder->note(ImportNote::INFO, 'Something happened.');
        }

        self::assertNotSame([], $at, 'A run this long flushed at least once.');

        $widest = 0;

        foreach ($at as $index => $count) {
            $widest = max($widest, $count - ($at[$index - 1] ?? 0));
        }

        self::assertLessThanOrEqual(
            500,
            $widest,
            'A run that keeps writing keeps a heartbeat, so it is never mistaken for a dead one.',
        );
    }
}
