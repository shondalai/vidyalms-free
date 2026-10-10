<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\QuestionType;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\ShortAnswerQuestionType;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class ShortAnswerQuestionTypeTest extends TestCase
{
    private ShortAnswerQuestionType $type;

    protected function setUp(): void
    {
        $this->type = new ShortAnswerQuestionType(new StubTranslator());
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return [
            'prompt' => 'Name the largest planet.',
            'accepted' => ['Jupiter'],
        ];
    }

    public function testKeyIsStable(): void
    {
        self::assertSame('short_answer', $this->type->key());
        self::assertSame('vidyalms.question_type.short_answer', $this->type->label());
    }

    public function testAcceptedMustBeAListOfStrings(): void
    {
        $this->expectException(ValidationException::class);
        $this->type->validateDefinition(['prompt' => 'Hm.', 'accepted' => [1, 2]]);
    }

    public function testEmptyAcceptedListIsValidButNeedsAHuman(): void
    {
        $definition = ['prompt' => 'Explain gravity in one sentence.', 'accepted' => []];
        $this->type->validateDefinition($definition);

        $result = $this->type->grade($definition, 'It pulls things together.');

        self::assertTrue($result->requiresManualGrading());
        self::assertSame(0.0, $result->fraction);
    }

    public function testTrimmedCaseInsensitiveMatchByDefault(): void
    {
        self::assertSame(1.0, $this->type->grade($this->definition(), '  jupiter  ')->fraction);
        self::assertSame(1.0, $this->type->grade($this->definition(), 'JUPITER')->fraction);
    }

    public function testCaseSensitiveModeRequiresExactCase(): void
    {
        $definition = $this->definition();
        $definition['case_sensitive'] = true;

        self::assertSame(0.0, $this->type->grade($definition, 'jupiter')->fraction);
        self::assertSame(1.0, $this->type->grade($definition, ' Jupiter ')->fraction);
    }

    public function testWrongOrNonStringAnswerIsIncorrect(): void
    {
        self::assertSame(0.0, $this->type->grade($this->definition(), 'Saturn')->fraction);
        self::assertSame(0.0, $this->type->grade($this->definition(), 42)->fraction);
        self::assertSame(0.0, $this->type->grade($this->definition(), null)->fraction);
    }

    public function testPresentationIsPromptOnly(): void
    {
        self::assertSame(
            ['prompt' => 'Name the largest planet.'],
            $this->type->presentForLearner($this->definition()),
        );
    }
}
