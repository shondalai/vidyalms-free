<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\QuestionType;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\SingleChoiceQuestionType;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class SingleChoiceQuestionTypeTest extends TestCase
{
    private SingleChoiceQuestionType $type;

    protected function setUp(): void
    {
        $this->type = new SingleChoiceQuestionType(new StubTranslator());
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return [
            'prompt' => 'Pick the capital of France.',
            'options' => [
                ['key' => 'a', 'label' => 'Paris'],
                ['key' => 'b', 'label' => 'Lyon'],
            ],
            'correct' => 'a',
            'feedback' => 'Paris it is.',
        ];
    }

    public function testKeyIsStable(): void
    {
        self::assertSame('single_choice', $this->type->key());
        self::assertSame('vidyalms.question_type.single_choice', $this->type->label());
    }

    public function testValidDefinitionPasses(): void
    {
        $this->type->validateDefinition($this->definition());
        $this->addToAssertionCount(1);
    }

    public function testMissingPromptIsRejected(): void
    {
        $definition = $this->definition();
        unset($definition['prompt']);

        $this->expectException(ValidationException::class);
        $this->type->validateDefinition($definition);
    }

    public function testCorrectMustBeAnOptionKey(): void
    {
        $definition = $this->definition();
        $definition['correct'] = 'z';

        $this->expectException(ValidationException::class);
        $this->type->validateDefinition($definition);
    }

    public function testDuplicateOptionKeysAreRejected(): void
    {
        $definition = $this->definition();
        $definition['options'][] = ['key' => 'a', 'label' => 'Again'];

        $this->expectException(ValidationException::class);
        $this->type->validateDefinition($definition);
    }

    public function testExactMatchIsCorrect(): void
    {
        $result = $this->type->grade($this->definition(), 'a');

        self::assertSame(1.0, $result->fraction);
        self::assertFalse($result->requiresManualGrading());
        self::assertSame('Paris it is.', $result->feedback);
    }

    public function testWrongKeyIsIncorrect(): void
    {
        self::assertSame(0.0, $this->type->grade($this->definition(), 'b')->fraction);
    }

    public function testNonStringAnswerIsIncorrect(): void
    {
        self::assertSame(0.0, $this->type->grade($this->definition(), ['a'])->fraction);
        self::assertSame(0.0, $this->type->grade($this->definition(), null)->fraction);
    }

    public function testPresentationKeepsPromptAndOptions(): void
    {
        $presented = $this->type->presentForLearner($this->definition());

        self::assertSame('Pick the capital of France.', $presented['prompt']);
        self::assertSame([
            ['key' => 'a', 'label' => 'Paris'],
            ['key' => 'b', 'label' => 'Lyon'],
        ], $presented['options']);
    }
}
