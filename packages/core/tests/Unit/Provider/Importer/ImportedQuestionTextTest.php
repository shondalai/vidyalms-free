<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\Importer;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Provider\Importer\Moodle\MoodleQuestionMapping;
use Shondalai\VidyaLMS\Core\Provider\Importer\CommunityQuizMapping;
use Shondalai\VidyaLMS\Core\Provider\Importer\QuestionText;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft\QuestionDraft;

final class ImportedQuestionTextTest extends TestCase
{
    public function testTextNotationAndImageAlternativesSurviveImport(): void
    {
        self::assertSame('What does <T> mean?', QuestionText::plain('What does <T> mean?'));
        self::assertSame('2 < 3', QuestionText::plain('2 < 3'));
        self::assertSame('Find x & y', QuestionText::plain('<img src="x.png" alt="Find x &amp; y">'));
        self::assertSame('[Image without alternative text]', QuestionText::plain('<img src="x.png">'));
    }
    public function testImportMappingsConvertDisplayTextAndRetainAnswerIdentifiers(): void
    {
        $definition = [
            'prompt' => '<p>Choose <strong>one</strong> &amp; continue.</p><script>alert(1)</script>',
            'options' => [['id' => 'a', 'text' => '<p>First &amp; best</p>']],
            'correct' => 'a',
            'accepted' => ['<b>First</b>'],
        ];
        foreach (
            [MoodleQuestionMapping::of('single_choice', $definition)->definition,
            QuestionDraft::of('1', 'single_choice', $definition)->definition,
            QuestionText::definition($definition)] as $converted
        ) {
            self::assertSame('Choose one & continue.', $converted['prompt']);
            self::assertSame([['id' => 'a', 'text' => 'First & best']], $converted['options']);
            self::assertSame('a', $converted['correct']);
            self::assertSame(['First'], $converted['accepted']);
        }
        $communityQuiz = CommunityQuizMapping::question([
            'type' => 'tf', 'stem' => '<p>Read <strong>this</strong> &amp; answer.</p>',
            'content' => json_encode(['answer' => true]),
        ], null);
        self::assertSame('Read this & answer.', $communityQuiz['definition']['prompt']);
        self::assertTrue($communityQuiz['definition']['correct']);
    }
}
