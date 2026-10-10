<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\QuestionType;

use Shondalai\VidyaLMS\Core\Provider\Scoring\FreeScoringPolicies;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PolicyBackedScoring;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;

/**
 * The free six's use of the published policy-backed scoring behaviour.
 *
 * Everything reusable moved to Registry\QuestionType\PolicyBackedScoring,
 * which is contract surface a paid or third-party type may depend on. What
 * stays here is the one thing that genuinely belongs to the free build: a
 * default registry to resolve against when nobody passed one, which is the
 * free ten.
 *
 * A package that ships policies of its own must not inherit that fallback, or
 * its own policy would be unresolvable in a detached probe. It supplies its
 * own scoringPolicies() instead, which is why that method is abstract on the
 * published trait rather than a private property this one owns. Reaching into
 * a private property of a free default from outside, which is what a paid
 * package used to do, is the sharpest form of exactly the coupling the split
 * removes.
 */
trait PolicyBackedGrading
{
    use PolicyBackedScoring;

    private ?ScoringPolicyRegistry $freePolicyFallback = null;

    /**
     * Resolved lazily rather than in a constructor, because a policy
     * registered after this type was built must still be resolvable.
     */
    protected function scoringPolicies(): ScoringPolicyRegistry
    {
        return $this->freePolicyFallback ??= FreeScoringPolicies::registry();
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function policyFeedback(array $definition): ?string
    {
        return DefinitionRules::feedback($definition);
    }
}
