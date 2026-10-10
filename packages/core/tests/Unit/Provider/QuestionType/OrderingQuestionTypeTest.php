<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\QuestionType;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\OrderingQuestionType;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class OrderingQuestionTypeTest extends TestCase
{
    private OrderingQuestionType $type;

    protected function setUp(): void
    {
        $this->type = new OrderingQuestionType(new StubTranslator());
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return [
            'prompt' => 'Order the planets from the sun outwards.',
            'items' => [
                ['key' => 'earth', 'label' => 'Earth'],
                ['key' => 'mercury', 'label' => 'Mercury'],
                ['key' => 'venus', 'label' => 'Venus'],
            ],
            'correct' => ['mercury', 'venus', 'earth'],
        ];
    }

    public function testKeyIsStable(): void
    {
        self::assertSame('ordering', $this->type->key());
        self::assertSame('vidyalms.question_type.ordering', $this->type->label());
    }

    public function testValidDefinitionPasses(): void
    {
        $this->type->validateDefinition($this->definition());
        $this->addToAssertionCount(1);
    }

    public function testCorrectMustBeAPermutationOfItemKeys(): void
    {
        $definition = $this->definition();
        $definition['correct'] = ['mercury', 'venus'];

        $this->expectException(ValidationException::class);
        $this->type->validateDefinition($definition);
    }

    public function testExactOrderScoresFullCredit(): void
    {
        self::assertSame(1.0, $this->type->grade($this->definition(), ['mercury', 'venus', 'earth'])->fraction);
    }

    public function testFractionCountsMatchingPositions(): void
    {
        // First position right, last two swapped.
        $result = $this->type->grade($this->definition(), ['mercury', 'earth', 'venus']);

        self::assertEqualsWithDelta(1 / 3, $result->fraction, 0.0001);
    }

    public function testCompletelyWrongOrderScoresZero(): void
    {
        self::assertSame(0.0, $this->type->grade($this->definition(), ['venus', 'earth', 'mercury'])->fraction);
    }

    /**
     * The assertion this replaces was the defect, not the fix.
     *
     * It asserted that a partial answer of ["mercury"] against a three-item
     * key scores 1/3, because grading counted matching positions without
     * ever checking that the response was an ordering at all. The same hole
     * paid 0.25 for ["a","a","a","a"] against a key of [a,b,c,d]. The design
     * closes it by validating the response as an exact permutation of the
     * presented item set before any policy runs.
     */
    public function testAResponseThatIsNotAPermutationScoresZero(): void
    {
        self::assertSame(0.0, $this->type->grade($this->definition(), ['mercury'])->fraction);
        self::assertSame(0.0, $this->type->grade($this->definition(), ['earth', 'earth', 'earth'])->fraction);
        self::assertSame(0.0, $this->type->grade($this->definition(), 'mercury')->fraction);
        self::assertSame(0.0, $this->type->grade($this->definition(), null)->fraction);
    }

    public function testPresentationKeepsItemsInAuthoredOrder(): void
    {
        $presented = $this->type->presentForLearner($this->definition());

        self::assertSame('Order the planets from the sun outwards.', $presented['prompt']);
        self::assertSame([
            ['key' => 'earth', 'label' => 'Earth'],
            ['key' => 'mercury', 'label' => 'Mercury'],
            ['key' => 'venus', 'label' => 'Venus'],
        ], $presented['items']);
    }
}
