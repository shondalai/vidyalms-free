<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Platform;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;

/**
 * The note is where an import's text stops being the source's and starts
 * being VidyaLMS's. Everything an importer writes quotes a title, a type or a
 * key it did not author, so the level has to be structured data and the
 * message has to be one bounded line: otherwise a course called
 * "Tea\n[error] everything was lost" writes a line into the job log that
 * nothing ever emitted, and every reader of that log believes it.
 */
final class ImportNoteTest extends TestCase
{
    public function testALineBreakInSourceTextCannotWriteASecondLogLine(): void
    {
        $note = ImportNote::info("Course \"Tea\n[error] Everything was lost.\" created.");

        self::assertStringNotContainsString("\n", $note->message);
        self::assertStringNotContainsString("\n", $note->line());
        self::assertSame(ImportNote::INFO, $note->level);
        self::assertSame('Course "Tea [error] Everything was lost." created.', $note->message);
    }

    public function testEveryControlCharacterBecomesASpace(): void
    {
        $note = ImportNote::warning("a\rb\tc\x00d\u{2028}e");

        self::assertSame('a b c d e', $note->message);
    }

    public function testTheLevelIsWhatTheNoteWasGivenAndNeverWhatTheMessageSays(): void
    {
        $forged = ImportNote::info('[error] Everything was lost.');

        self::assertSame(ImportNote::INFO, $forged->level);
        self::assertSame('[error] Everything was lost.', $forged->message, 'The text stands.');

        // The level does not follow the text, and the stored line says so:
        // the writer marks its own level in the one case where a plain line
        // would be read back as the level the source text names.
        $parsed = ImportNote::parseLog($forged->line());

        self::assertCount(1, $parsed);
        self::assertSame(ImportNote::INFO, $parsed[0]->level, 'A line the writer produced is read as the writer wrote it.');
        self::assertSame('[error] Everything was lost.', $parsed[0]->message);
    }

    /**
     * line() and fromLine() are one pair, and a stored log is only readable
     * because they are inverses. Source text is where that is tested: every
     * message an importer composes quotes a title, a type or an exception it
     * did not write, and a title that opens with something shaped like a
     * level marker must not be able to change the level of the line carrying
     * it. Writing the log twice is tested too, because AbandonImportJob reads
     * a log back and writes it again.
     *
     * @return list<array{string, string}>
     */
    public static function hostileMessages(): array
    {
        $messages = [
            'Course "Tea" created.',
            '[error] Everything was lost.',
            '[warning] Nothing came across.',
            '[info] Course "Tea" created.',
            '[error] [error] [warning] doubled.',
            '[error]no space, so no marker',
            '[ERROR] Everything was lost.',
            '[catastrophe] not a level at all',
            '[error] ',
            '[error]',
            '',
            '   [error] leading spaces are trimmed away first',
            str_repeat('t', ImportNote::MESSAGE_LIMIT + 500),
            '[error] ' . str_repeat('t', ImportNote::MESSAGE_LIMIT),
        ];

        $cases = [];

        foreach ([ImportNote::INFO, ImportNote::WARNING, ImportNote::ERROR] as $level) {
            foreach ($messages as $message) {
                $cases[] = [$level, $message];
            }
        }

        return $cases;
    }

    #[DataProvider('hostileMessages')]
    public function testALineAlwaysReadsBackAsTheNoteThatWroteIt(string $level, string $message): void
    {
        $note = ImportNote::of($level, $message);
        $read = ImportNote::fromLine($note->line());

        self::assertSame($note->level, $read->level, 'The level survives the round trip.');
        self::assertSame($note->message, $read->message, 'The message survives the round trip.');

        // And writing what was read produces the same line again, so a log
        // parsed and stored a second time is the same log.
        self::assertSame($note->line(), $read->line());
    }

    #[DataProvider('hostileMessages')]
    public function testAHostileMessageCannotForgeALevelInAStoredLog(string $level, string $message): void
    {
        $notes = [ImportNote::info('Course "Tea" created.'), ImportNote::of($level, $message)];
        $read = ImportNote::parseLog((new ImportResult([], $notes))->log());

        self::assertCount(2, $read, 'One note is one line.');
        self::assertSame(ImportNote::INFO, $read[0]->level);
        self::assertSame($notes[1]->level, $read[1]->level);
        self::assertSame($notes[1]->message, $read[1]->message);
    }

    public function testALogRoundTripsThroughItsOwnParser(): void
    {
        $notes = [
            ImportNote::info('Course "Tea" created.'),
            ImportNote::warning('Course "Tea": drip did not come across.'),
            ImportNote::error('Community Quiz course 8 could not be imported: the table is missing.'),
        ];

        $log = (new ImportResult([], $notes))->log();
        $read = ImportNote::parseLog($log);

        self::assertSame(
            [[ImportNote::INFO, $notes[0]->message], [ImportNote::WARNING, $notes[1]->message], [ImportNote::ERROR, $notes[2]->message]],
            array_map(static fn (ImportNote $note): array => [$note->level, $note->message], $read),
        );
    }

    public function testAnUnknownLevelIsInfoRatherThanItself(): void
    {
        self::assertSame(ImportNote::INFO, ImportNote::of('catastrophe', 'Something happened.')->level);
    }

    public function testSourceTextIsQuotedAsAnExcerptAndTheWholeNoteIsBounded(): void
    {
        $long = str_repeat('t', 5000);

        $excerpt = ImportNote::excerpt($long);
        self::assertLessThanOrEqual(ImportNote::EXCERPT_LIMIT, \strlen($excerpt));
        self::assertStringEndsWith('...', $excerpt);

        $note = ImportNote::warning(sprintf('Lesson "%s" was not imported.', $long));
        self::assertLessThanOrEqual(ImportNote::MESSAGE_LIMIT, \strlen($note->message));
        self::assertStringEndsWith('...', $note->message);
    }

    public function testAnExcerptNeverCutsAUtf8CharacterInHalf(): void
    {
        $excerpt = ImportNote::excerpt(str_repeat('é', 200), 21);

        self::assertLessThanOrEqual(21, \strlen($excerpt));
        self::assertSame(1, preg_match('//u', $excerpt), 'The excerpt is still valid UTF-8.');
    }

    public function testAnEmptyLogHasNoNotes(): void
    {
        self::assertSame([], ImportNote::parseLog(null));
        self::assertSame([], ImportNote::parseLog('   '));
    }
}
