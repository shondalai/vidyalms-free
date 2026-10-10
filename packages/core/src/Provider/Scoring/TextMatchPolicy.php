<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Scoring;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;
use Shondalai\VidyaLMS\Core\Registry\Scoring\PolicyParameters;
use Shondalai\VidyaLMS\Core\Registry\Scoring\RawScore;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Scoring\TextMatcher;

/**
 * An ordered matcher list, first match wins, each entry carrying its own
 * fraction and feedback id.
 *
 * The matcher kinds, the case-sensitivity precedence, the normalisation
 * pipeline and the two safety rules for wildcards and author-supplied
 * regexes all live in TextMatcher and TextNormalisation, so a type that
 * offers text matching gets them without restating any of it.
 *
 * The flag set actually applied is recorded on the grade row, so a regrade
 * reproduces the mark rather than re-deriving it from whatever the defaults
 * are on the day.
 */
final class TextMatchPolicy implements ScoringPolicyInterface
{
    public const KEY = 'text_match';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Text match';
    }

    public function appliesToShapes(): array
    {
        return ScoringShapes::textual();
    }

    public function paramsSchema(): array
    {
        return PolicyParameters::schema([]);
    }

    public function defaultParams(): array
    {
        return [PolicyParameters::FLOOR_FRACTION => 0.0];
    }

    public function validateParams(array $params): void
    {
        PolicyParameters::rejectUnknown($params, [], self::KEY);
    }

    public function score(ResponseValue $response, ScoringKey $key, array $params, ScoringContext $context): RawScore
    {
        $floor = PolicyParameters::floor($params);
        $applied = [PolicyParameters::FLOOR_FRACTION => $floor, 'matchers' => \count($key->matchers)];

        if ($key->matchers === []) {
            return RawScore::notScored($applied + ['reason' => 'no_matchers']);
        }

        // The flag set actually applied is recorded on the grade row so a
        // regrade is reproducible, and the clause is unconditional. Recording
        // it only on the matched branch left it missing from exactly the row
        // most likely to be disputed: a zero on a short answer. A regrade
        // then re-derives the pipeline from whatever the defaults are on the
        // day rather than reproducing what ran.
        $applied['normalisation_by_matcher'] = self::flagsOf($key->matchers);

        $text = $response->asString();

        if ($text === null) {
            return new RawScore(
                0.0,
                raw: 0.0,
                min: 0.0,
                max: 1.0,
                floorFraction: $floor,
                // Not a "reason": that key means the item is misconfigured
                // and cannot be marked at all, which a learner writing
                // nothing is not.
                params: $applied + ['response' => 'not_a_string'],
            );
        }

        foreach ($key->matchers as $index => $matcher) {
            if (!$matcher instanceof TextMatcher || !$matcher->matches($text)) {
                continue;
            }

            return new RawScore(
                $matcher->fraction,
                raw: $matcher->fraction,
                min: 0.0,
                max: 1.0,
                floorFraction: $floor,
                feedbackIds: $matcher->feedbackId === null ? [] : [$matcher->feedbackId],
                params: $applied + [
                    'matched_index' => $index,
                    'matched_kind' => $matcher->kind,
                    'normalisation' => $matcher->appliedFlags(),
                ],
            );
        }

        return new RawScore(0.0, raw: 0.0, min: 0.0, max: 1.0, floorFraction: $floor, params: $applied);
    }

    /**
     * The flag set each matcher would apply, in authored order.
     *
     * Per matcher rather than one shared set, because a matcher owns its own
     * normalisation and a future shape may vary it within one question.
     *
     * @param list<mixed> $matchers
     *
     * @return list<array<string, mixed>>
     */
    private static function flagsOf(array $matchers): array
    {
        $flags = [];

        foreach ($matchers as $matcher) {
            $flags[] = $matcher instanceof TextMatcher ? $matcher->appliedFlags() : [];
        }

        return $flags;
    }
}
