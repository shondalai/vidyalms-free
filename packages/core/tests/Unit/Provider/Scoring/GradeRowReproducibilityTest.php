<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\Scoring;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Provider\Scoring\NonePolicy;
use Shondalai\VidyaLMS\Core\Provider\Scoring\TextMatchPolicy;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;
use Shondalai\VidyaLMS\Core\Registry\Scoring\TextMatcher;

/**
 * What a grade row has to be able to say after the fact.
 *
 * Both cases here are rows a dispute would be answered from, and both were
 * missing the one fact that answers it.
 */
final class GradeRowReproducibilityTest extends TestCase
{
    /**
     * The design pins the none policy to raw 0.0, min 0.0, max 0.0. The
     * generic notScored() leaves min and max null, and those reach
     * attempt_grades.min_score, so the append-only history recorded an absent
     * bound rather than the zero the row is supposed to document.
     */
    public function testTheNonePolicyRecordsItsPinnedBoundsRatherThanNulls(): void
    {
        $result = (new NonePolicy())->score(
            ResponseValue::of(ResponseShape::of(Cardinality::Single, BaseType::Identifier), 'a'),
            ScoringKey::value('a'),
            [],
            ScoringContext::defaults(),
        );

        self::assertSame(ScoreStatus::NotScored, $result->status);
        self::assertSame(0.0, $result->raw);
        self::assertSame(0.0, $result->min, 'min is pinned to 0.0, not left absent');
        self::assertSame(0.0, $result->max, 'max is pinned to 0.0, not left absent');
    }

    /**
     * The flag set actually applied is recorded on the grade row so a regrade
     * is reproducible, and the clause is unconditional. Recording it only
     * when a matcher hit left it missing from exactly the row most likely to
     * be disputed: a zero on a short answer.
     */
    public function testTheAppliedNormalisationIsRecordedWhenNothingMatched(): void
    {
        $key = ScoringKey::matchers([
            new TextMatcher(
                TextMatcher::KIND_EXACT_CI,
                'water',
                normalisation: ['punctuation_to_space' => true, 'form' => 'nfkc'],
            ),
        ]);
        $policy = new TextMatchPolicy();
        $shape = ResponseShape::of(Cardinality::Single, BaseType::StringValue);

        $matched = $policy->score(
            ResponseValue::of($shape, 'water'),
            $key,
            [],
            ScoringContext::defaults(),
        );
        $missed = $policy->score(
            ResponseValue::of($shape, 'lava'),
            $key,
            [],
            ScoringContext::defaults(),
        );

        self::assertSame(1.0, $matched->fraction);
        self::assertSame(0.0, $missed->fraction);

        self::assertArrayHasKey('normalisation', $matched->params);
        self::assertArrayHasKey(
            'normalisation_by_matcher',
            $missed->params,
            'a zero has to say which pipeline produced it, or a regrade re-derives it from the defaults of the day',
        );
        self::assertSame(
            [$matched->params['normalisation']],
            $missed->params['normalisation_by_matcher'],
            'the flags recorded on the zero are the same flags the match would have applied',
        );
    }
}
