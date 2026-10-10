<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\Importer;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CompletionRule;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseVisibility;
use Shondalai\VidyaLMS\Core\Provider\Importer\CommunityQuizMapping;

/**
 * The reading of Community Quiz's own shapes, which is where a migration is
 * usually wrong: the JSON columns, the free-text duration and the rows that
 * cannot become a valid definition at all.
 */
final class CommunityQuizMappingTest extends TestCase
{
    public function testItReadsTheDurationsCommunityQuizAccepts(): void
    {
        self::assertSame(760, CommunityQuizMapping::durationSeconds('12:40'));
        self::assertSame(240, CommunityQuizMapping::durationSeconds('4:00'));
        self::assertSame(600, CommunityQuizMapping::durationSeconds('10'), 'A bare number is minutes.');
        self::assertSame(900, CommunityQuizMapping::durationSeconds('15 min'));
        self::assertNull(CommunityQuizMapping::durationSeconds(''));
        self::assertNull(CommunityQuizMapping::durationSeconds('a while'));
    }

    /**
     * A duration is free text in Community Quiz, so a nonsense one is a
     * matter of time. Multiplying it by sixty used to overflow into a float,
     * which a function declared ?int may not return: the TypeError that threw
     * is an Error, no DomainException handler caught it, and one row ended
     * the whole migration. A number no clock could mean is no duration.
     */
    public function testADurationTooLargeToStoreIsNoDurationRatherThanAFatalError(): void
    {
        self::assertNull(CommunityQuizMapping::durationSeconds('99999999999999999999'));
        self::assertNull(CommunityQuizMapping::durationSeconds('9999999999:59'));
        self::assertNull(CommunityQuizMapping::durationSeconds(str_repeat('9', 400)));
        self::assertSame(0, CommunityQuizMapping::durationSeconds('0'));
        self::assertNull(CommunityQuizMapping::durationSeconds(null), 'A column that is null is no duration.');
        self::assertNull(CommunityQuizMapping::durationSeconds(['12:40']), 'Neither is a shape no column has.');
        self::assertSame(720, CommunityQuizMapping::durationSeconds(12), 'A number reads as minutes, like its text does.');
    }

    public function testAQuizTimeLimitIsMinutesAndRefusesWhatNoColumnHolds(): void
    {
        self::assertSame(1800, CommunityQuizMapping::minutesAsSeconds(30));
        self::assertSame(1800, CommunityQuizMapping::minutesAsSeconds('30'));
        self::assertNull(CommunityQuizMapping::minutesAsSeconds(0), 'Community Quiz spells "no limit" as zero.');
        self::assertNull(CommunityQuizMapping::minutesAsSeconds(-5));
        self::assertNull(CommunityQuizMapping::minutesAsSeconds(200000000000000000));
        self::assertNull(CommunityQuizMapping::minutesAsSeconds('half an hour'));
    }

    public function testThePassMarkIsAPercentageOfTheQuizScale(): void
    {
        self::assertSame(60, CommunityQuizMapping::passMark(['pass_mark' => 30, 'scale_max' => 50]));
        self::assertSame(75, CommunityQuizMapping::passMark(['pass_mark' => 75, 'scale_max' => 100]));
        self::assertSame(0, CommunityQuizMapping::passMark(['pass_mark' => 0, 'scale_max' => 100]));
        self::assertSame(0, CommunityQuizMapping::passMark(['pass_mark' => 10, 'scale_max' => 0]), 'A scale of zero is no scale.');
        self::assertSame(100, CommunityQuizMapping::passMark(['pass_mark' => 90, 'scale_max' => 50]), 'Never above a hundred.');
    }

    public function testTheCompletionRuleAndVisibilityColumns(): void
    {
        self::assertSame(
            ['rule' => CompletionRule::Percentage, 'threshold' => 90],
            CommunityQuizMapping::completion(['rule' => 'ninety_pct']),
        );
        self::assertSame(
            ['rule' => CompletionRule::PassFinal, 'threshold' => null],
            CommunityQuizMapping::completion(['rule' => 'pass_final']),
        );
        self::assertSame(
            ['rule' => CompletionRule::AllLessons, 'threshold' => null],
            CommunityQuizMapping::completion(['rule' => 'something_later']),
            'A rule the free build does not know falls back to every lesson.',
        );

        self::assertSame(CourseVisibility::Public, CommunityQuizMapping::visibility(['visibility' => 'public', 'show_in_catalog' => 1]));
        self::assertSame(CourseVisibility::Unlisted, CommunityQuizMapping::visibility(['visibility' => 'public', 'show_in_catalog' => 0]));
        self::assertSame(CourseVisibility::Private, CommunityQuizMapping::visibility(['visibility' => 'private', 'show_in_catalog' => 1]));
        self::assertSame(CourseVisibility::Public, CommunityQuizMapping::visibility([]));
    }

    public function testJsonColumnsAreReadTheWayCommunityQuizWritesThem(): void
    {
        self::assertSame(['rule' => 'all_lessons'], CommunityQuizMapping::decode('{"rule":"all_lessons"}'));
        self::assertSame([], CommunityQuizMapping::decode(null));
        self::assertSame([], CommunityQuizMapping::decode(''));
        self::assertSame([], CommunityQuizMapping::decode('{ not json'), 'A broken column is no configuration, not a failure.');
        self::assertSame([], CommunityQuizMapping::decode('"a string"'));
        self::assertSame(['already' => 'decoded'], CommunityQuizMapping::decode(['already' => 'decoded']));
    }

    public function testASingleBlankKeepsItsSentenceWithTheGapWhereItWas(): void
    {
        $underscores = CommunityQuizMapping::question([
            'type' => 'blank',
            'stem' => 'Fill it in.',
            'content' => ['template' => 'Water boils at ____ degrees.', 'answers' => ['100'], 'caseSensitive' => true],
        ], null);

        self::assertSame('fill_blank', $underscores['type']);
        self::assertSame("Fill it in.\nWater boils at [[blank1]] degrees.", $underscores['definition']['prompt']);
        self::assertTrue($underscores['definition']['case_sensitive']);

        $placeholder = CommunityQuizMapping::question([
            'type' => 'blank',
            'stem' => 'Fill it in.',
            'content' => ['template' => 'Water boils at {{answer}} degrees.', 'answers' => ['100']],
        ], null);
        self::assertSame("Fill it in.\nWater boils at [[blank1]] degrees.", $placeholder['definition']['prompt']);

        // A template with no gap in it still has to end up with one, because
        // the free type validates that every blank is marked in the prompt.
        $unmarked = CommunityQuizMapping::question([
            'type' => 'blank',
            'stem' => 'Name the boiling point.',
            'content' => ['template' => 'In degrees celsius.', 'answers' => ['100']],
        ], null);
        self::assertSame("Name the boiling point.\nIn degrees celsius. [[blank1]]", $unmarked['definition']['prompt']);
    }

    public function testAClozeBlankWithNoPlaceholderIsAppendedAndSaidSo(): void
    {
        $mapped = CommunityQuizMapping::question([
            'type' => 'cloze',
            'stem' => 'Complete the passage.',
            'content' => [
                'template' => 'Steep for {{minutes}} minutes.',
                'blanks' => ['minutes' => '4', 'degrees' => '80'],
            ],
        ], null);

        self::assertSame('Steep for [[minutes]] minutes. [[degrees]]', $mapped['definition']['prompt']);
        self::assertSame(
            [['key' => 'minutes', 'accepted' => ['4']], ['key' => 'degrees', 'accepted' => ['80']]],
            $mapped['definition']['blanks'],
        );
        self::assertNotNull($mapped['note']);
        self::assertStringContainsString('had no placeholder in the passage', (string) $mapped['note']);
    }

    public function testTheOrderingAnswerBecomesTheCorrectSequenceOfItemKeys(): void
    {
        $mapped = CommunityQuizMapping::question([
            'type' => 'order',
            'stem' => 'Order the steps.',
            'content' => ['items' => ['Pour', 'Pick', 'Steep'], 'answer' => [1, 2, 0]],
            'points' => 4,
        ], null);

        self::assertSame('ordering', $mapped['type']);
        self::assertSame(
            [['key' => 'i1', 'label' => 'Pour'], ['key' => 'i2', 'label' => 'Pick'], ['key' => 'i3', 'label' => 'Steep']],
            $mapped['definition']['items'],
        );
        self::assertSame(['i2', 'i3', 'i1'], $mapped['definition']['correct']);
        self::assertSame(4.0, $mapped['points']);
    }

    public function testAPlacementOverridesThePointsAndAZeroNeverSurvives(): void
    {
        $row = [
            'type' => 'tf',
            'stem' => 'Tea is a leaf.',
            'content' => ['answer' => 1],
            'points' => 5,
        ];

        self::assertSame(2.5, CommunityQuizMapping::question($row, 2.5)['points']);
        self::assertSame(5.0, CommunityQuizMapping::question($row, null)['points']);

        $worthless = ['points' => 0] + $row;
        self::assertSame(
            1.0,
            CommunityQuizMapping::question($worthless, null)['points'],
            'A question worth nothing cannot be added, so it is worth one.',
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableQuestions')]
    public function testAQuestionThatCannotProduceAValidDefinitionSaysWhy(array $row, string $reason): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage($reason);

        CommunityQuizMapping::question($row, null);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function unusableQuestions(): array
    {
        return [
            'no stem at all' => [['type' => 'mcq', 'content' => ['options' => ['a', 'b'], 'answer' => 0]], 'has no stem'],
            'one option' => [
                ['type' => 'mcq', 'stem' => 'Pick.', 'content' => ['options' => ['a'], 'answer' => 0]],
                'fewer than two options',
            ],
            'answer out of range' => [
                ['type' => 'mcq', 'stem' => 'Pick.', 'content' => ['options' => ['a', 'b'], 'answer' => 7]],
                'correct option is out of range',
            ],
            'nothing marked correct' => [
                ['type' => 'multi', 'stem' => 'Pick.', 'content' => ['options' => ['a', 'b'], 'answer' => []]],
                'marks no correct option',
            ],
            'neither true nor false' => [['type' => 'tf', 'stem' => 'True?', 'content' => []], 'neither true nor false'],
            'no accepted answer' => [['type' => 'short', 'stem' => 'Name it.', 'content' => ['answers' => []]], 'accepts no answer'],
            'ordering that misses an item' => [
                ['type' => 'order', 'stem' => 'Order.', 'content' => ['items' => ['a', 'b'], 'answer' => [0]]],
                'does not list every item',
            ],
            'ordering that repeats one' => [
                ['type' => 'order', 'stem' => 'Order.', 'content' => ['items' => ['a', 'b'], 'answer' => [0, 0]]],
                'lists an item twice',
            ],
            'a type that belongs to Pro' => [['type' => 'hotspot', 'stem' => 'Click.', 'content' => []], 'has no free equivalent'],
        ];
    }

    public function testTheLessonTypesThatCarryAcrossAndTheOnesThatDoNot(): void
    {
        self::assertTrue(CommunityQuizMapping::supportsLesson('reading'));
        self::assertTrue(CommunityQuizMapping::supportsLesson('resource'));
        self::assertFalse(CommunityQuizMapping::supportsLesson('live'));
        self::assertFalse(CommunityQuizMapping::supportsLesson('scorm'));

        $vimeo = CommunityQuizMapping::lesson(
            ['type' => 'video', 'content' => ['source_url' => 'https://player.vimeo.com/video/7']],
            null,
        );
        self::assertSame(['provider' => 'vimeo', 'url' => 'https://player.vimeo.com/video/7'], $vimeo['content']);

        $elsewhere = CommunityQuizMapping::lesson(
            ['type' => 'video', 'content' => ['source_url' => 'https://cdn.example.test/lesson.mp4']],
            null,
        );
        self::assertSame('url', $elsewhere['content']['provider']);

        $link = CommunityQuizMapping::lesson(
            ['type' => 'resource', 'title' => 'Notes & slides', 'content' => ['resource_url' => 'https://example.test/a.pdf?x=1&y=2']],
            null,
        );
        self::assertSame('reading', $link['type'], 'A linked resource becomes a reading that links to it.');
        self::assertSame(
            ['body' => '<p><a href="https://example.test/a.pdf?x=1&amp;y=2" rel="noopener">Notes &amp; slides</a></p>'],
            $link['content'],
            'The URL and the title are escaped into the body.',
        );
        self::assertNotNull($link['note']);
    }

    public function testALessonThatCannotProduceValidContentSaysWhy(): void
    {
        $cases = [
            [['type' => 'reading', 'content' => ['body' => '  ']], 'the reading has no body'],
            [['type' => 'video', 'content' => ['source_url' => 'ftp://example.test/x.mp4']], 'the video has no http URL'],
            [['type' => 'resource', 'content' => []], 'the resource has no URL'],
            [['type' => 'quiz', 'content' => []], 'its quiz was not imported'],
            [['type' => 'live', 'content' => []], 'lesson type "live" has no free equivalent'],
        ];

        foreach ($cases as [$row, $reason]) {
            try {
                CommunityQuizMapping::lesson($row, null);
                self::fail(sprintf('"%s" should not have mapped.', (string) $row['type']));
            } catch (\RuntimeException $failure) {
                self::assertStringContainsString($reason, $failure->getMessage());
            }
        }
    }
}
