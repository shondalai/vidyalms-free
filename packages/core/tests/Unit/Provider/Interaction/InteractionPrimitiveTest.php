<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\Interaction;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\ErrorKind;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\Seed;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\ShufflePolicy;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Provider\Interaction\ArtifactPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\AssociatePrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\OrderPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\ScalarPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\SelectPrimitive;
use Shondalai\VidyaLMS\Core\Registry\Context\DecodeContext;

/**
 * The constraints are enforced once per primitive, for every type that uses
 * it, including a third-party type whose author never read the
 * specification. These are those enforcements.
 */
final class InteractionPrimitiveTest extends TestCase
{
    private function selectShape(): ResponseShape
    {
        return ResponseShape::of(Cardinality::Multiple, BaseType::Identifier);
    }

    public function testTooManySelectionsIsInvalidRatherThanWrong(): void
    {
        $primitive = new SelectPrimitive();
        $shape = $this->selectShape();
        $context = DecodeContext::create($shape);

        $constraints = $primitive->projectConstraints([
            'max_selections' => 2,
            'max_message' => 'Pick no more than two.',
        ]);
        $value = $primitive->decodeResponse(['a', 'b', 'c'], $shape, $context);

        $validity = $primitive->validateAnswer($constraints, $value, $context);

        self::assertFalse($validity->isValid());
        self::assertSame(ErrorKind::TooManySelected->value, $validity->errors[0]->kind);
        self::assertSame('Pick no more than two.', $validity->errors[0]->message);
        self::assertSame(
            \Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus::Attempted,
            $validity->answeredStatus(),
        );
    }

    public function testTooFewSelectionsCarriesTheAuthoredMessage(): void
    {
        $primitive = new SelectPrimitive();
        $shape = $this->selectShape();
        $context = DecodeContext::create($shape);

        $constraints = $primitive->projectConstraints([
            'min_selections' => 2,
            'min_message' => 'Pick at least two.',
        ]);
        $value = $primitive->decodeResponse(['a'], $shape, $context);

        $validity = $primitive->validateAnswer($constraints, $value, $context);

        self::assertSame(ErrorKind::TooFewSelected->value, $validity->errors[0]->kind);
        self::assertSame('Pick at least two.', $validity->errors[0]->message);
    }

    public function testAnEmptyResponseIsValidButNotAnswered(): void
    {
        $primitive = new SelectPrimitive();
        $shape = $this->selectShape();
        $context = DecodeContext::create($shape);

        $validity = $primitive->validateAnswer(
            $primitive->projectConstraints(['min_selections' => 2]),
            $primitive->decodeResponse(null, $shape, $context),
            $context,
        );

        self::assertTrue($validity->isValid());
        self::assertSame(
            \Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus::Presented,
            $validity->answeredStatus(),
        );
    }

    public function testTheOrderPrimitiveRefusesAResponseThatIsNotAPermutation(): void
    {
        $primitive = new OrderPrimitive();
        $shape = ResponseShape::of(Cardinality::Ordered, BaseType::Identifier);
        $context = DecodeContext::create($shape);

        $constraints = $primitive->projectConstraints([
            'items' => [['key' => 'a'], ['key' => 'b'], ['key' => 'c']],
        ]);

        $invalid = $primitive->validateAnswer(
            $constraints,
            $primitive->decodeResponse(['a', 'a', 'a'], $shape, $context),
            $context,
        );
        self::assertSame(ErrorKind::InvalidPermutation->value, $invalid->errors[0]->kind);

        $valid = $primitive->validateAnswer(
            $constraints,
            $primitive->decodeResponse(['c', 'a', 'b'], $shape, $context),
            $context,
        );
        self::assertTrue($valid->isValid());
    }

    public function testTheAssociatePrimitiveEnforcesPerChoiceMatchMax(): void
    {
        $primitive = new AssociatePrimitive();
        $shape = ResponseShape::of(Cardinality::Multiple, BaseType::DirectedPair);
        $context = DecodeContext::create($shape);

        $constraints = $primitive->projectConstraints([
            'sources' => [['key' => 's1', 'match_max' => 1]],
            'targets' => [['key' => 't1'], ['key' => 't2']],
        ]);

        $validity = $primitive->validateAnswer(
            $constraints,
            $primitive->decodeResponse([['s1', 't1'], ['s1', 't2']], $shape, $context),
            $context,
        );

        self::assertFalse($validity->isValid());
        self::assertSame('s1', $validity->errors[0]->controlId);
    }

    public function testTheScalarPrimitiveEnforcesItsBounds(): void
    {
        $primitive = new ScalarPrimitive();
        $shape = ResponseShape::of(Cardinality::Single, BaseType::Float);
        $context = DecodeContext::create($shape);
        $constraints = $primitive->projectConstraints(['lower' => 0, 'upper' => 10]);

        self::assertTrue($primitive->validateAnswer($constraints, $primitive->decodeResponse(5, $shape, $context), $context)->isValid());

        $tooHigh = $primitive->validateAnswer($constraints, $primitive->decodeResponse(11, $shape, $context), $context);
        self::assertSame(ErrorKind::OutOfRange->value, $tooHigh->errors[0]->kind);

        $notANumber = $primitive->validateAnswer($constraints, $primitive->decodeResponse('five', $shape, $context), $context);
        self::assertSame(ErrorKind::ValueNotNumeric->value, $notANumber->errors[0]->kind);
    }

    public function testTheArtifactPrimitiveRefusesAnUnacceptedFileType(): void
    {
        $primitive = new ArtifactPrimitive();
        $shape = ResponseShape::of(Cardinality::Multiple, BaseType::File);
        $context = DecodeContext::create($shape);
        $constraints = $primitive->projectConstraints(['accepted_types' => ['application/pdf']]);

        $validity = $primitive->validateAnswer(
            $constraints,
            $primitive->decodeResponse([['mime' => 'image/gif']], $shape, $context),
            $context,
        );

        self::assertSame(ErrorKind::UnsupportedFileType->value, $validity->errors[0]->kind);
    }

    /**
     * The shuffle honours the per-choice fixed flag once, here, so a
     * third-party type inherits it without writing a line of it.
     */
    public function testThePresentationSequenceHonoursTheFixedFlag(): void
    {
        $primitive = new SelectPrimitive();
        $definition = [
            'options' => [
                ['key' => 'a', 'label' => 'A'],
                ['key' => 'b', 'label' => 'B'],
                ['key' => 'c', 'label' => 'C'],
                ['key' => 'none', 'label' => 'None of the above', 'fixed' => true],
            ],
        ];

        $sequence = $primitive->presentationSequence($definition, Seed::of('seed-1'), ShufflePolicy::on());

        self::assertSame('none', $sequence->for('options')[3]);
        self::assertCount(4, $sequence->for('options'));
    }

    public function testNoSequenceIsProducedWhenShuffleIsOff(): void
    {
        $primitive = new SelectPrimitive();

        $sequence = $primitive->presentationSequence(
            ['options' => [['key' => 'a'], ['key' => 'b']]],
            Seed::of('seed-1'),
            ShufflePolicy::off(),
        );

        self::assertTrue($sequence->isEmpty());
    }

    /**
     * Shuffling is suppressed for a learner profile that asks for a
     * simplified layout, and the reason is recorded rather than lost.
     */
    public function testAnAccessibilityProfileSuppressesShuffleAndSaysSo(): void
    {
        $policy = ShufflePolicy::resolve(true, true);

        self::assertFalse($policy->enabled);
        self::assertSame(ShufflePolicy::REASON_ACCESSIBILITY_PROFILE, $policy->suppressedBecause);
    }

    /**
     * A contributed error kind is namespaced, so a vendor cannot claim a
     * core name and two vendors cannot collide.
     */
    public function testAContributedErrorKindMustBeNamespaced(): void
    {
        self::assertTrue(ErrorKind::isWellFormed('TOO_MANY_SELECTED'));
        self::assertTrue(ErrorKind::isWellFormed('x_acme_word_limit'));
        self::assertFalse(ErrorKind::isWellFormed('WORD_LIMIT'));
        self::assertFalse(ErrorKind::isWellFormed('acme_word_limit'));
    }

    public function testTheDecodeContextKnowsTheLocaleDecimalSeparator(): void
    {
        $shape = ResponseShape::of(Cardinality::Single, BaseType::Float);

        self::assertSame('.', DecodeContext::create($shape, 'en-GB')->decimalSeparator());
        self::assertSame(',', DecodeContext::create($shape, 'de-DE')->decimalSeparator());
    }
}
