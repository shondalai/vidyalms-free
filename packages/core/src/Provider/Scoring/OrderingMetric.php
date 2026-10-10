<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Scoring;

/**
 * The chance correction every ordering policy shares.
 *
 *   fraction = max( 0, (m - E) / (1 - E) )
 *
 * where m is the raw metric in 0 to 1 and E is its expectation under a
 * uniform random permutation of n items.
 *
 * Why correction rather than the raw metrics. Every one of them pays
 * substantially for a random answer. The expected number of discordant pairs
 * is exactly half the total for every n, so raw Kendall tau has an expected
 * value of 0.5 for a guesser at every list length. The earlier reasoning
 * treated the cyclic shift D,A,B,C scoring 0.5 as the argument for making
 * Kendall tau the default, when 0.5 is precisely the guessing baseline: the
 * shift was earning nothing above chance while reading as half marks. After
 * correction it scores 0, a maximally wrong reversal scores 0, a random
 * order expects 0, and one adjacent transposition on four items is 0.833 raw
 * and 0.667 corrected.
 *
 * chance_corrected is a policy parameter, default true for a new item and
 * false wherever fidelity is the point: an import that pins a policy to
 * reproduce a migrating teacher's marks pins the correction off, and so does
 * the free-build migration that pins existing ordering questions to
 * absolute_position.
 */
final class OrderingMetric
{
    public const PARAM_CHANCE_CORRECTED = 'chance_corrected';

    private function __construct()
    {
    }

    public static function correct(float $metric, float $baseline, bool $enabled): float
    {
        if (!$enabled || $baseline >= 1.0) {
            return $metric;
        }

        return max(0.0, ($metric - $baseline) / (1.0 - $baseline));
    }

    /**
     * The proportion of concordant pairs: 1 - discordant / (n(n-1)/2).
     *
     * @param list<string> $response
     * @param list<string> $key
     */
    public static function kendallTau(array $response, array $key): float
    {
        $n = \count($key);

        if ($n < 2) {
            return 1.0;
        }

        $rank = array_flip($key);
        $sequence = [];

        foreach ($response as $member) {
            $sequence[] = $rank[$member] ?? -1;
        }

        $discordant = 0;

        for ($i = 0; $i < $n - 1; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                if (($sequence[$i] ?? -1) > ($sequence[$j] ?? -1)) {
                    ++$discordant;
                }
            }
        }

        return 1.0 - ($discordant / ($n * ($n - 1) / 2));
    }

    /**
     * Exact position matches over n.
     *
     * @param list<string> $response
     * @param list<string> $key
     */
    public static function absolutePosition(array $response, array $key): float
    {
        $n = \count($key);

        if ($n === 0) {
            return 0.0;
        }

        $matching = 0;

        foreach ($key as $index => $expected) {
            if (($response[$index] ?? null) === $expected) {
                ++$matching;
            }
        }

        return $matching / $n;
    }

    /**
     * An exact permutation of the presented item set: every key exactly
     * once, nothing else.
     *
     * This runs before any policy. Without it [A,A,A,A] against [A,B,C,D]
     * scores 0.25 under absolute position, which is a quarter of the marks
     * for a response that is not an ordering at all.
     *
     * @param list<string> $response
     * @param list<string> $key
     */
    public static function isPermutationOf(array $response, array $key): bool
    {
        if (\count($response) !== \count($key)) {
            return false;
        }

        $given = $response;
        $wanted = $key;
        sort($given);
        sort($wanted);

        return $given === $wanted;
    }
}
