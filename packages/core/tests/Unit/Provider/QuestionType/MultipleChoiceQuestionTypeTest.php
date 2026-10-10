<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\QuestionType;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\MultipleChoiceQuestionType;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class MultipleChoiceQuestionTypeTest extends TestCase
{
    private MultipleChoiceQuestionType $type;

    protected function setUp(): void
    {
        $this->type = new MultipleChoiceQuestionType(new StubTranslator());
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return [
            'prompt' => 'Select every prime number.',
            'options' => [
                ['key' => 'two', 'label' => '2'],
                ['key' => 'three', 'label' => '3'],
                ['key' => 'four', 'label' => '4'],
                ['key' => 'five', 'label' => '5'],
            ],
            'correct' => ['two', 'three', 'five'],
        ];
    }

    public function testKeyIsStable(): void
    {
        self::assertSame('multiple_choice', $this->type->key());
        self::assertSame('vidyalms.question_type.multiple_choice', $this->type->label());
    }

    public function testValidDefinitionPasses(): void
    {
        $this->type->validateDefinition($this->definition());
        $this->addToAssertionCount(1);
    }

    public function testEmptyCorrectListIsRejected(): void
    {
        $definition = $this->definition();
        $definition['correct'] = [];

        $this->expectException(ValidationException::class);
        $this->type->validateDefinition($definition);
    }

    public function testCorrectKeysMustBeOptionKeys(): void
    {
        $definition = $this->definition();
        $definition['correct'] = ['two', 'nine'];

        $this->expectException(ValidationException::class);
        $this->type->validateDefinition($definition);
    }

    public function testAllCorrectSelectionsScoreFullCredit(): void
    {
        $result = $this->type->grade($this->definition(), ['two', 'three', 'five']);

        self::assertSame(1.0, $result->fraction);
    }

    public function testPartialCreditSubtractsWrongSelections(): void
    {
        // Two correct minus one incorrect over three correct keys.
        $result = $this->type->grade($this->definition(), ['two', 'three', 'four']);

        self::assertEqualsWithDelta(1 / 3, $result->fraction, 0.0001);
    }

    public function testNegativeBalanceFloorsAtZero(): void
    {
        $result = $this->type->grade($this->definition(), ['four']);

        self::assertSame(0.0, $result->fraction);
    }

    public function testDuplicateSelectionsCountOnce(): void
    {
        $result = $this->type->grade($this->definition(), ['two', 'two', 'two']);

        self::assertEqualsWithDelta(1 / 3, $result->fraction, 0.0001);
    }

    public function testEmptyOrInvalidAnswerScoresZero(): void
    {
        self::assertSame(0.0, $this->type->grade($this->definition(), [])->fraction);
        self::assertSame(0.0, $this->type->grade($this->definition(), 'two')->fraction);
        self::assertSame(0.0, $this->type->grade($this->definition(), null)->fraction);
    }
}
