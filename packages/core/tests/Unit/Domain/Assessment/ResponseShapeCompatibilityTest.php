<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Domain\Assessment;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseField;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;

/**
 * The record signature is what a policy binds to, so it has to be compared.
 *
 * Treating every record as compatible with every other record recreated
 * exactly the wildcard the signature exists to prevent: without the
 * comparison, record cannot tell numeric's {stringValue, floatValue, unit,
 * nsf, ndp} from an extended response's {text, files}, so numeric_precision
 * becomes offerable on an essay and per_target becomes offerable on a
 * recording. Deferring the check to each policy's validateParams means every
 * future record-shaped policy reimplements it, and the one that forgets is
 * offerable on the wrong type with no test able to catch it generically.
 */
final class ResponseShapeCompatibilityTest extends TestCase
{
    public function testTwoDifferentRecordSignaturesAreNotCompatible(): void
    {
        self::assertFalse(self::numeric()->isCompatibleWith(self::extendedText()));
        self::assertFalse(self::extendedText()->isCompatibleWith(self::numeric()));
    }

    public function testTheSameSignatureIsCompatibleWhateverTheFieldOrder(): void
    {
        $reordered = ResponseShape::record([
            new ResponseField('files', Cardinality::Multiple, BaseType::File),
            new ResponseField('text', Cardinality::Single, BaseType::StringValue),
        ]);

        self::assertTrue(self::extendedText()->isCompatibleWith($reordered));
        self::assertTrue($reordered->isCompatibleWith(self::extendedText()));
    }

    /**
     * anyRecord() stays the wildcard a decorator declares deliberately, so
     * certainty weighting and the rubric policies keep applying everywhere.
     */
    public function testTheExplicitWildcardStillMatchesEveryRecord(): void
    {
        self::assertTrue(ResponseShape::anyRecord()->isCompatibleWith(self::numeric()));
        self::assertTrue(self::numeric()->isCompatibleWith(ResponseShape::anyRecord()));
        self::assertTrue(ResponseShape::anyRecord()->isCompatibleWith(self::extendedText()));
    }

    public function testCardinalityStillSeparatesNonRecords(): void
    {
        $single = ResponseShape::of(Cardinality::Single, BaseType::Identifier);
        $multiple = ResponseShape::of(Cardinality::Multiple, BaseType::Identifier);

        self::assertFalse($single->isCompatibleWith($multiple));
        self::assertFalse($single->isCompatibleWith(self::numeric()));
        self::assertTrue($single->isCompatibleWith(ResponseShape::of(Cardinality::Single, BaseType::Identifier)));
    }

    private static function numeric(): ResponseShape
    {
        return ResponseShape::record([
            new ResponseField('stringValue', Cardinality::Single, BaseType::StringValue),
            new ResponseField('floatValue', Cardinality::Single, BaseType::Float),
        ]);
    }

    private static function extendedText(): ResponseShape
    {
        return ResponseShape::record([
            new ResponseField('text', Cardinality::Single, BaseType::StringValue),
            new ResponseField('files', Cardinality::Multiple, BaseType::File),
        ]);
    }
}
