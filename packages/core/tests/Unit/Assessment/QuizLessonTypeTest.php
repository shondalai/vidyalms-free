<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Assessment;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\LessonType\QuizLessonType;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\LessonType\PresentsFreePreview;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class QuizLessonTypeTest extends TestCase
{
    private QuizLessonType $type;

    protected function setUp(): void
    {
        $this->type = new QuizLessonType(new StubTranslator());
    }

    public function testKeyIsQuiz(): void
    {
        self::assertSame('quiz', $this->type->key());
    }

    public function testLabelComesFromTheTranslator(): void
    {
        self::assertSame('vidyalms.lesson_type.quiz', $this->type->label());
    }

    public function testCompletionIsOnPass(): void
    {
        self::assertSame(LessonTypeInterface::COMPLETION_ON_PASS, $this->type->completionMode());
    }

    public function testValidContentPasses(): void
    {
        $this->type->validateContent(['quiz_id' => 7]);
        $this->addToAssertionCount(1);
    }

    public function testMissingQuizIdIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->type->validateContent([]);
    }

    public function testNonPositiveQuizIdIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->type->validateContent(['quiz_id' => 0]);
    }

    public function testStringQuizIdIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->type->validateContent(['quiz_id' => '7']);
    }

    public function testPresentationExposesOnlyTheQuizId(): void
    {
        self::assertSame(['quiz_id' => 7], $this->type->presentForLearner(['quiz_id' => 7], true));
    }

    /**
     * The quiz id is the handle a client starts an attempt with, so a reader
     * the gate refused is given none. The type ignored the flag, so a
     * drip-locked quiz lesson handed out its id alongside an access block
     * that said blocked.
     */
    public function testPresentationWithoutAccessDisclosesNoQuizId(): void
    {
        self::assertSame(['quiz_id' => 0], $this->type->presentForLearner(['quiz_id' => 7], false));
    }

    /**
     * The decision in 13-learning-flow-and-credentials.md: a quiz preview is
     * its overview, which travels in the lesson's quiz summary. The id is
     * only a handle to start an attempt with, and an attempt needs an
     * enrolment, so a preview is handed none, for the reason the no-access
     * presentation withholds it: the two answers stay consistent.
     */
    public function testThePreviewWithholdsTheAttemptHandle(): void
    {
        self::assertInstanceOf(PresentsFreePreview::class, $this->type);
        self::assertSame(['quiz_id' => 0], $this->type->presentForPreview(['quiz_id' => 7]));
    }
}
