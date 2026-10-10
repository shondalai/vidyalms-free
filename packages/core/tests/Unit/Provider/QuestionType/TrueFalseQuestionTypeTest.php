<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\QuestionType;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\TrueFalseQuestionType;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class TrueFalseQuestionTypeTest extends TestCase
{
    private TrueFalseQuestionType $type;

    protected function setUp(): void
    {
        $this->type = new TrueFalseQuestionType(new StubTranslator());
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(bool $correct = true): array
    {
        return [
            'prompt' => 'The sky is blue.',
            'correct' => $correct,
        ];
    }

    public function testKeyIsStable(): void
    {
        self::assertSame('true_false', $this->type->key());
        self::assertSame('vidyalms.question_type.true_false', $this->type->label());
    }

    public function testNonBooleanCorrectIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->type->validateDefinition(['prompt' => 'Hm.', 'correct' => 'yes']);
    }

    public function testMatchingBooleanIsCorrect(): void
    {
        self::assertSame(1.0, $this->type->grade($this->definition(true), true)->fraction);
        self::assertSame(1.0, $this->type->grade($this->definition(false), false)->fraction);
    }

    public function testMismatchIsIncorrect(): void
    {
        self::assertSame(0.0, $this->type->grade($this->definition(true), false)->fraction);
    }

    public function testIntegerZeroAndOneAreAcceptedAsBooleans(): void
    {
        self::assertSame(1.0, $this->type->grade($this->definition(true), 1)->fraction);
        self::assertSame(1.0, $this->type->grade($this->definition(false), 0)->fraction);
    }

    public function testOtherAnswerShapesAreIncorrect(): void
    {
        self::assertSame(0.0, $this->type->grade($this->definition(true), 'true')->fraction);
        self::assertSame(0.0, $this->type->grade($this->definition(true), null)->fraction);
        self::assertSame(0.0, $this->type->grade($this->definition(true), 2)->fraction);
    }

    public function testPresentationIsPromptOnly(): void
    {
        self::assertSame(['prompt' => 'The sky is blue.'], $this->type->presentForLearner($this->definition()));
    }
}
