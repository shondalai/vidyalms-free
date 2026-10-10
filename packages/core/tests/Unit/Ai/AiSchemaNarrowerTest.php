<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Ai;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Ai\AiSchemaNarrower;
use Shondalai\VidyaLMS\Core\Application\Ai\SchemaStrengthenedException;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionContractInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionSchemaCheck;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The narrower, proven against the six free question types' own published
 * schemas rather than against a fixture written to suit it.
 *
 * The rule under test is the corrected one. An earlier statement said the
 * narrower asserts the narrowing is a subset and refuses a **widening**,
 * which resolves to "refuse every route" for every shipped type: dropping a
 * constraint is a widening by definition. What it refuses is a
 * **strengthening**, which is the transformation that could reject a
 * definition the type's own schema accepts, and the negative case at the
 * bottom is the one that proves the refusal still exists.
 */
final class AiSchemaNarrowerTest extends TestCase
{
    /** The keyword set a conservative strict mode really enforces. */
    private const CONSERVATIVE = ['type', 'required', 'enum'];

    public function testEveryFreeTypeNarrowsRatherThanBeingRefused(): void
    {
        $narrower = new AiSchemaNarrower();
        $narrowed = 0;

        foreach ($this->freeSchemas() as $key => $schema) {
            $result = $narrower->narrow($schema, self::CONSERVATIVE);

            self::assertNotNull($result->schema, $key . ' produced no guidance at all');
            self::assertNotSame(
                [],
                $result->droppedConstraints,
                $key . ' should have given something up against a conservative keyword set',
            );
            ++$narrowed;
        }

        self::assertSame(6, $narrowed, 'the free build ships exactly six question types');
    }

    public function testTheNarrowedSchemaIsNeverStrongerThanTheTypesOwn(): void
    {
        $narrower = new AiSchemaNarrower();

        foreach ($this->freeSchemas() as $key => $schema) {
            $result = $narrower->narrow($schema, self::CONSERVATIVE);

            self::assertNotNull($result->schema);
            $narrower->assertNotStronger($schema, $result->schema);
            self::addToAssertionCount(1);
        }
    }

    public function testEveryValidDefinitionStillPassesTheNarrowedGuidance(): void
    {
        $narrower = new AiSchemaNarrower();

        foreach ($this->freeTypes() as $key => $type) {
            $schema = $type->definitionSchema();
            $narrowed = $narrower->narrow($schema, self::CONSERVATIVE)->schema;
            self::assertNotNull($narrowed);

            // The example definition satisfies the type's own schema, so a
            // narrowing that could reject a document the original accepts
            // would show up here as an error against the weaker document.
            self::assertSame(
                [],
                DefinitionSchemaCheck::errors($narrowed, $type->exampleDefinition(), '', true),
                $key . ' narrowed to something its own example no longer satisfies',
            );
        }
    }

    public function testKeywordsTheNarrowerUnderstandsMatchTheDefinitionCheckers(): void
    {
        $understood = new \ReflectionClassConstant(DefinitionSchemaCheck::class, 'UNDERSTOOD');
        /** @var list<string> $checkers */
        $checkers = $understood->getValue();

        self::assertSame(
            $checkers,
            AiSchemaNarrower::UNDERSTOOD,
            'a keyword the definition checker understands and this class does not is a keyword the '
            . 'narrower refuses outright, which would take a whole question type off every route',
        );
    }

    public function testDroppingEverythingLeavesTheStructureAndNamesEveryConstraint(): void
    {
        $narrower = new AiSchemaNarrower();
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['prompt'],
            'properties' => [
                'prompt' => ['type' => 'string', 'minLength' => 1],
                'options' => ['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'string']],
            ],
        ];

        $result = $narrower->unenforced($schema);

        self::assertNull($result->schema);
        self::assertFalse($result->isFullyEnforced());
        self::assertSame(
            [
                'type',
                'additionalProperties',
                'required',
                'prompt.type',
                'prompt.minLength',
                'options.type',
                'options.minItems',
                'options.items.type',
            ],
            $result->droppedConstraints,
        );
    }

    public function testASupportedKeywordSetKeepsExactlyWhatItNames(): void
    {
        $result = (new AiSchemaNarrower())->narrow(
            [
                'type' => 'object',
                'required' => ['prompt'],
                'properties' => ['prompt' => ['type' => 'string', 'minLength' => 3]],
            ],
            ['type', 'required'],
        );

        self::assertSame(
            [
                'type' => 'object',
                'required' => ['prompt'],
                'properties' => ['prompt' => ['type' => 'string']],
            ],
            $result->schema,
        );
        self::assertSame(['prompt.minLength'], $result->droppedConstraints);
    }

    public function testANarrowingThatTightensABoundIsRefused(): void
    {
        $original = ['type' => 'object', 'properties' => ['prompt' => ['type' => 'string', 'minLength' => 1]]];
        $stronger = ['type' => 'object', 'properties' => ['prompt' => ['type' => 'string', 'minLength' => 5]]];

        $this->expectException(SchemaStrengthenedException::class);
        (new AiSchemaNarrower())->assertNotStronger($original, $stronger);
    }

    public function testANarrowingThatAddsAConstraintIsRefused(): void
    {
        $original = ['type' => 'object', 'properties' => ['prompt' => ['type' => 'string']]];
        $stronger = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => ['prompt' => ['type' => 'string']],
        ];

        $this->expectException(SchemaStrengthenedException::class);
        (new AiSchemaNarrower())->assertNotStronger($original, $stronger);
    }

    public function testANarrowingThatInventsAPropertyIsRefused(): void
    {
        $original = ['type' => 'object', 'properties' => ['prompt' => ['type' => 'string']]];
        $stronger = [
            'type' => 'object',
            'properties' => [
                'prompt' => ['type' => 'string'],
                'suggested_mark' => ['type' => 'number'],
            ],
        ];

        $this->expectException(SchemaStrengthenedException::class);
        (new AiSchemaNarrower())->assertNotStronger($original, $stronger);
    }

    public function testAKeywordTheDefinitionCheckerCannotEvaluateIsRefusedRatherThanDropped(): void
    {
        $this->expectException(SchemaStrengthenedException::class);
        (new AiSchemaNarrower())->narrow(['type' => 'object', 'oneOf' => []], ['type']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function freeSchemas(): array
    {
        $schemas = [];

        foreach ($this->freeTypes() as $key => $type) {
            $schemas[$key] = $type->definitionSchema();
        }

        return $schemas;
    }

    /**
     * @return array<string, DefinitionContractInterface>
     */
    private function freeTypes(): array
    {
        $types = [];

        foreach (ManagementFixture::create()->kernel->registries->questionTypes->all() as $type) {
            if ($type instanceof DefinitionContractInterface) {
                $types[$type->key()] = $type;
            }
        }

        return $types;
    }
}
