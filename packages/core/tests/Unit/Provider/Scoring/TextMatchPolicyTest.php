<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\Scoring;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Provider\Scoring\TextMatchPolicy;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;
use Shondalai\VidyaLMS\Core\Registry\Scoring\TextMatcher;
use Shondalai\VidyaLMS\Core\Registry\Scoring\TextNormalisation;

final class TextMatchPolicyTest extends TestCase
{
    private TextMatchPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new TextMatchPolicy();
    }

    /**
     * @param list<TextMatcher> $matchers
     */
    private function score(string $answer, array $matchers): float
    {
        return $this->policy->score(
            ResponseValue::of(ResponseShape::of(Cardinality::Single, BaseType::StringValue), $answer),
            ScoringKey::matchers($matchers),
            $this->policy->defaultParams(),
            ScoringContext::defaults(),
        )->fraction;
    }

    /**
     * Case sensitivity is a property of the matcher and of nothing else.
     * exact never folds, exact_ci always folds, and a question-level fold
     * flag applies only to the kinds that do not fix it themselves.
     */
    public function testExactNeverFoldsCaseEvenWithTheQuestionLevelFlagOn(): void
    {
        $matcher = new TextMatcher(
            TextMatcher::KIND_EXACT,
            'Pb',
            normalisation: [TextNormalisation::FLAG_CASE_FOLD => true],
        );

        self::assertSame(1.0, $this->score('Pb', [$matcher]));
        self::assertSame(0.0, $this->score('PB', [$matcher]), 'the symbol for lead is not PB');
    }

    public function testExactCiAlwaysFoldsCaseEvenWithTheFlagOff(): void
    {
        $matcher = new TextMatcher(
            TextMatcher::KIND_EXACT_CI,
            'Jupiter',
            normalisation: [TextNormalisation::FLAG_CASE_FOLD => false],
        );

        self::assertSame(1.0, $this->score('JUPITER', [$matcher]));
    }

    public function testFirstMatchWinsAndCarriesItsOwnFraction(): void
    {
        $matchers = [
            new TextMatcher(TextMatcher::KIND_EXACT_CI, 'carbon dioxide', 1.0, 'right'),
            new TextMatcher(TextMatcher::KIND_CONTAINS, 'carbon', 0.5, 'partial'),
        ];

        self::assertSame(1.0, $this->score('Carbon Dioxide', $matchers));
        self::assertSame(0.5, $this->score('carbon monoxide', $matchers));
    }

    public function testTheMatchedFeedbackIdTravelsWithTheResult(): void
    {
        $result = $this->policy->score(
            ResponseValue::of(ResponseShape::of(Cardinality::Single, BaseType::StringValue), 'carbon monoxide'),
            ScoringKey::matchers([
                new TextMatcher(TextMatcher::KIND_EXACT_CI, 'carbon dioxide', 1.0, 'right'),
                new TextMatcher(TextMatcher::KIND_CONTAINS, 'carbon', 0.5, 'partial'),
            ]),
            $this->policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame(['partial'], $result->feedbackIds);
        self::assertSame(1, $result->params['matched_index']);
    }

    public function testAWildcardIsEscapedBeforeCompilation(): void
    {
        $matcher = new TextMatcher(TextMatcher::KIND_WILDCARD, 'a*z');

        self::assertSame(1.0, $this->score('abcz', [$matcher]));
        self::assertSame(0.0, $this->score('abc', [$matcher]));
        // A regex metacharacter in the pattern is literal, not a quantifier.
        self::assertSame(0.0, $this->score('aaaz', [new TextMatcher(TextMatcher::KIND_WILDCARD, 'a+z')]));
        self::assertSame(1.0, $this->score('a+z', [new TextMatcher(TextMatcher::KIND_WILDCARD, 'a+z')]));
    }

    public function testABackslashAsteriskIsALiteralAsterisk(): void
    {
        $matcher = new TextMatcher(TextMatcher::KIND_WILDCARD, 'a\\*z');

        self::assertSame(1.0, $this->score('a*z', [$matcher]));
        self::assertSame(0.0, $this->score('abcz', [$matcher]));
    }

    public function testAnAuthoredRegexIsAnchored(): void
    {
        $matcher = new TextMatcher(TextMatcher::KIND_REGEX, 'cat');

        self::assertSame(1.0, $this->score('cat', [$matcher]));
        self::assertSame(0.0, $this->score('concatenate', [$matcher]), 'an unanchored /cat/ would match');
    }

    /**
     * The clinically opposite pair a normalised similarity threshold would
     * accept: "hypotension" against "hypertension" is distance 2, similarity
     * 0.833.
     */
    public function testLevenshteinAcceptsOnAnAbsoluteEditDistance(): void
    {
        $oneEdit = new TextMatcher(TextMatcher::KIND_LEVENSHTEIN, 'hypertension', maxEdits: 1);

        self::assertSame(1.0, $this->score('hypertensio', [$oneEdit]));
        self::assertSame(0.0, $this->score('hypotension', [$oneEdit]));
    }

    public function testLevenshteinRefusesMoreThanTwoEdits(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TextMatcher(TextMatcher::KIND_LEVENSHTEIN, 'x', maxEdits: 3);
    }

    public function testInternalWhitespaceIsCollapsedAndTheStringIsTrimmed(): void
    {
        $matcher = new TextMatcher(TextMatcher::KIND_EXACT_CI, 'carbon dioxide');

        self::assertSame(1.0, $this->score('  carbon   dioxide  ', [$matcher]));
    }

    /**
     * Punctuation is replaced by a space rather than removed, and only when
     * the author asks. Stripping it collapses "carbon-dioxide" to
     * "carbondioxide", which fails a key of "carbon dioxide".
     */
    public function testPunctuationBecomesASpaceRatherThanDisappearing(): void
    {
        $matcher = new TextMatcher(
            TextMatcher::KIND_EXACT_CI,
            'carbon dioxide',
            normalisation: [TextNormalisation::FLAG_PUNCTUATION_TO_SPACE => true],
        );

        self::assertSame(1.0, $this->score('carbon-dioxide', [$matcher]));
    }

    public function testNfkcIsOptInBecauseItDestroysTheConstruct(): void
    {
        $matcher = new TextMatcher(TextMatcher::KIND_EXACT_CI, "10\u{00B2}");

        // NFC by default: the superscript two is not the digit two.
        self::assertSame(0.0, $this->score('102', [$matcher]));

        $compatibility = new TextMatcher(
            TextMatcher::KIND_EXACT_CI,
            "10\u{00B2}",
            normalisation: [TextNormalisation::FLAG_FORM => TextNormalisation::FORM_NFKC],
        );

        self::assertSame(
            class_exists(\Normalizer::class) ? 1.0 : 0.0,
            $this->score('102', [$compatibility]),
        );
    }

    public function testTheFlagSetActuallyAppliedIsRecordedOnTheResult(): void
    {
        $result = $this->policy->score(
            ResponseValue::of(ResponseShape::of(Cardinality::Single, BaseType::StringValue), 'Pb'),
            ScoringKey::matchers([new TextMatcher(TextMatcher::KIND_EXACT, 'Pb')]),
            $this->policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertIsArray($result->params['normalisation']);
        self::assertFalse($result->params['normalisation'][TextNormalisation::FLAG_CASE_FOLD]);
    }

    public function testAnEmptyMatcherListIsNotScoredRatherThanWrong(): void
    {
        $result = $this->policy->score(
            ResponseValue::of(ResponseShape::of(Cardinality::Single, BaseType::StringValue), 'anything'),
            ScoringKey::matchers([]),
            $this->policy->defaultParams(),
            ScoringContext::defaults(),
        );

        self::assertSame('no_matchers', $result->params['reason']);
    }
}
