<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Assessment;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\Seed;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseCodec;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseField;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\StoredQuestionDefinition;

final class ResponseVocabularyTest extends TestCase
{
    public function testARecordShapeCarriesItsFieldSignature(): void
    {
        $numericish = ResponseShape::record([
            new ResponseField('stringValue', Cardinality::Single, BaseType::StringValue),
            new ResponseField('floatValue', Cardinality::Single, BaseType::Float),
        ]);
        $essayish = ResponseShape::record([
            new ResponseField('text', Cardinality::Single, BaseType::StringValue),
            new ResponseField('files', Cardinality::Multiple, BaseType::File),
        ]);

        // Without the signature, "record" is a wildcard that cannot tell one
        // from the other, and a precision policy becomes offerable on an
        // essay.
        self::assertNotSame($numericish->key(), $essayish->key());
    }

    public function testARecordShapeNeedsAFieldSignature(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ResponseShape::record([]);
    }

    public function testTheColumnValueFitsTheColumn(): void
    {
        $wide = ResponseShape::record([
            new ResponseField('averyverylongfieldnameindeed', Cardinality::Single, BaseType::StringValue),
            new ResponseField('anotherverylongfieldname', Cardinality::Multiple, BaseType::File),
        ]);

        self::assertLessThanOrEqual(30, \strlen($wide->columnValue()));
        self::assertLessThanOrEqual(
            30,
            \strlen(ResponseShape::of(Cardinality::Multiple, BaseType::DirectedPair)->columnValue()),
        );
    }

    /**
     * The envelope and the free build's bare shapes are one grading path,
     * not two.
     */
    public function testTheCodecAcceptsBothWireShapes(): void
    {
        $single = ResponseShape::of(Cardinality::Single, BaseType::Identifier);
        self::assertSame('c2', ResponseCodec::decode('c2', $single)->value);
        self::assertSame('c2', ResponseCodec::decode(['base' => ['identifier' => 'c2']], $single)->value);

        $multiple = ResponseShape::of(Cardinality::Multiple, BaseType::Identifier);
        self::assertSame(['a', 'b'], ResponseCodec::decode(['a', 'b'], $multiple)->members());
        self::assertSame(
            ['a', 'b'],
            ResponseCodec::decode(['list' => ['identifier' => ['a', 'b']]], $multiple)->members(),
        );
    }

    public function testANullEnvelopeDecodesAsNull(): void
    {
        $shape = ResponseShape::of(Cardinality::Single, BaseType::Identifier);

        self::assertTrue(ResponseCodec::decode(['base' => null], $shape)->isNullOrEmpty());
        self::assertTrue(ResponseCodec::decode(null, $shape)->isNullOrEmpty());
    }

    /**
     * A bare scalar where a container belongs is malformed, not a one-member
     * container. Promoting it would turn a client bug into a partial mark.
     */
    public function testABareScalarIsNotPromotedIntoAContainer(): void
    {
        $multiple = ResponseShape::of(Cardinality::Multiple, BaseType::Identifier);

        self::assertSame([], ResponseCodec::decode('a', $multiple)->members());
    }

    public function testTrueFalseAcceptsTheIntegersAFormPostSends(): void
    {
        $boolean = ResponseShape::of(Cardinality::Single, BaseType::Boolean);

        self::assertTrue(ResponseCodec::decode(1, $boolean)->asBool());
        self::assertFalse(ResponseCodec::decode(0, $boolean)->asBool());
    }

    public function testDistinctMembersPreserveFirstSubmittedOrder(): void
    {
        $multiple = ResponseShape::of(Cardinality::Multiple, BaseType::Identifier);
        $value = ResponseCodec::decode(['b', 'a', 'b'], $multiple);

        self::assertSame(['b', 'a'], $value->distinctMembers());
        self::assertSame(['b', 'a', 'b'], $value->members());
    }

    public function testTwoEqualDefinitionsShareOneContentAddress(): void
    {
        $one = StoredQuestionDefinition::of('single_choice', 1, ['prompt' => 'p', 'correct' => 'a']);
        $other = StoredQuestionDefinition::of('single_choice', 1, ['correct' => 'a', 'prompt' => 'p']);

        self::assertSame($one->hash, $other->hash, 'key order must not create a second row');
    }

    public function testADifferentVersionIsADifferentAddress(): void
    {
        $one = StoredQuestionDefinition::of('single_choice', 1, ['prompt' => 'p']);
        $other = StoredQuestionDefinition::of('single_choice', 2, ['prompt' => 'p']);

        self::assertNotSame($one->hash, $other->hash);
    }

    /**
     * The seed has to be deterministic or a stored seed buys nothing, and it
     * has to honour the fixed flag or every "None of the above" item breaks
     * the moment shuffling ships.
     */
    public function testTheSeededShuffleIsStableAndHonoursFixed(): void
    {
        $seed = Seed::of('abcdef');
        $keys = ['a', 'b', 'c', 'none'];

        $first = $seed->shuffle('options', $keys, ['none']);
        $second = $seed->shuffle('options', $keys, ['none']);

        self::assertSame($first, $second, 'a reload must not reorder');
        self::assertSame('none', $first[3], 'a fixed choice keeps its index');
        self::assertSame(['a', 'b', 'c', 'none'], self::sorted($first), 'nothing is lost or invented');
    }

    public function testTwoFieldsOfOneDefinitionDoNotShuffleIdentically(): void
    {
        $seed = Seed::of('abcdef');
        $keys = ['a', 'b', 'c', 'd', 'e', 'f'];

        self::assertNotSame($seed->shuffle('options', $keys), $seed->shuffle('items', $keys));
    }

    public function testTwoSeedsGiveDifferentOrders(): void
    {
        $keys = ['a', 'b', 'c', 'd', 'e', 'f'];

        self::assertNotSame(
            Seed::of('one')->shuffle('options', $keys),
            Seed::of('two')->shuffle('options', $keys),
        );
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private static function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }
}
