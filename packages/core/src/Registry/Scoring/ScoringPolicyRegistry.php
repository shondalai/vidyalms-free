<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Scoring;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;
use Shondalai\VidyaLMS\Core\Registry\UnknownProviderException;

/**
 * The marking rules this site can apply.
 *
 * The free build fills it with ten providers even though the free six barely
 * stretch it, for the reason ADR 0009 already gives for the type registry: a
 * seam only Pro uses is a seam nobody tests.
 *
 * The miss is defined here rather than left to callers. Pro provider keys
 * are written into a real questions.scoring_policy column, so a miss can
 * arrive through a free-typed question authored with a Pro policy. An
 * unresolved policy scores raw 0 with score status notscored and an
 * explanatory row, never a wrong answer.
 *
 * @extends AbstractProviderRegistry<ScoringPolicyInterface>
 */
final class ScoringPolicyRegistry extends AbstractProviderRegistry
{
    protected function providerInterface(): string
    {
        return ScoringPolicyInterface::class;
    }

    /**
     * The policy named, or the null object that records the miss. Never
     * throws, because a resolvable question with an unresolvable policy must
     * still submit, still render and still reach the grading queue.
     */
    public function resolve(?string $key): ScoringPolicyInterface
    {
        if ($key === null || $key === '') {
            return new UnresolvedScoringPolicy('');
        }

        try {
            return $this->get($key);
        } catch (UnknownProviderException) {
            return new UnresolvedScoringPolicy($key);
        }
    }

    /**
     * The policy that will actually read the ScoringKey, once the decorators
     * an item names have been followed down to their base.
     *
     * A type builds one key and hands it to whichever policy the item names,
     * and the key constructors are not interchangeable: a set key carries the
     * correct members and no mapping, a mapped key carries QTI's mapping
     * object and no members. An item marked by certainty weighting over
     * map_response needs the mapped key even though the policy it names is
     * certainty_weighted, and handing it the wrong one grades every response
     * as not scored rather than failing.
     *
     * The walk lives here because the registry is the only side that can ask
     * each policy for its own declared default base. A copy that hardcodes
     * one default is right until the first decorator whose default is not
     * match_correct, and then it is a silent mis-grade.
     *
     * @param array<string, mixed> $params
     */
    public function effectiveKey(?string $key, array $params): string
    {
        $current = $key ?? '';

        for ($depth = 0; $depth < PolicyDecoration::MAX_DEPTH; ++$depth) {
            $policy = $this->resolve($current);

            if (!$policy instanceof DecoratingPolicyInterface) {
                return $current;
            }

            $next = $policy->basePolicyKey($params);

            if ($next === '' || $next === $current) {
                return $current;
            }

            $base = $params[PolicyDecoration::PARAM_BASE] ?? null;
            $params = \is_array($base) && \is_array($base['params'] ?? null) ? $base['params'] : [];
            /** @var array<string, mixed> $params */
            $current = $next;
        }

        return $current;
    }
}
