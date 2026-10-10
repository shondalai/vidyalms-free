<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\QuestionType;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\FillBlankQuestionType;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class FillBlankQuestionTypeTest extends TestCase
{
    private FillBlankQuestionType $type;

    protected function setUp(): void
    {
        $this->type = new FillBlankQuestionType(new StubTranslator());
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return [
            'prompt' => 'Water is made of [[first]] and [[second]].',
            'blanks' => [
                ['key' => 'first', 'accepted' => ['hydrogen']],
                ['key' => 'second', 'accepted' => ['oxygen']],
            ],
        ];
    }

    public function testKeyIsStable(): void
    {
        self::assertSame('fill_blank', $this->type->key());
        self::assertSame('vidyalms.question_type.fill_blank', $this->type->label());
    }

    public function testValidDefinitionPasses(): void
    {
        $this->type->validateDefinition($this->definition());
        $this->addToAssertionCount(1);
    }

    public function testPromptMustContainEveryMarker(): void
    {
        $definition = $this->definition();
        $definition['prompt'] = 'Water is made of [[first]] and something.';

        $this->expectException(ValidationException::class);
        $this->type->validateDefinition($definition);
    }

    public function testBlankWithoutAcceptedAnswersIsRejected(): void
    {
        $definition = $this->definition();
        $definition['blanks'][1] = ['key' => 'second', 'accepted' => []];

        $this->expectException(ValidationException::class);
        $this->type->validateDefinition($definition);
    }

    public function testAllBlanksMatchedScoresFullCredit(): void
    {
        $result = $this->type->grade($this->definition(), ['first' => ' Hydrogen ', 'second' => 'OXYGEN']);

        self::assertSame(1.0, $result->fraction);
    }

    public function testFractionIsMatchedOverTotal(): void
    {
        $result = $this->type->grade($this->definition(), ['first' => 'hydrogen', 'second' => 'carbon']);

        self::assertSame(0.5, $result->fraction);
    }

    public function testCaseSensitiveModeAppliesPerBlank(): void
    {
        $definition = $this->definition();
        $definition['case_sensitive'] = true;

        $result = $this->type->grade($definition, ['first' => 'Hydrogen', 'second' => 'oxygen']);

        self::assertSame(0.5, $result->fraction);
    }

    public function testMissingOrInvalidAnswerScoresZero(): void
    {
        self::assertSame(0.0, $this->type->grade($this->definition(), [])->fraction);
        self::assertSame(0.0, $this->type->grade($this->definition(), 'hydrogen oxygen')->fraction);
    }

    public function testPresentationExposesBlankKeysOnly(): void
    {
        $presented = $this->type->presentForLearner($this->definition());

        self::assertSame('Water is made of [[first]] and [[second]].', $presented['prompt']);
        self::assertSame([['key' => 'first'], ['key' => 'second']], $presented['blanks']);
    }
}
