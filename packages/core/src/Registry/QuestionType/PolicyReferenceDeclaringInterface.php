<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyInterface;

/**
 * A type that knows where in its own definition the marking rule lives, and
 * what that rule resolves to.
 *
 * `questions.scoring_policy` and `questions.scoring_params` are derived
 * mirrors: real indexed columns reporting filters and groups on, written by
 * the same code path that writes the definition, with the design's own
 * requirement that they cannot diverge from what actually grades. Without
 * this interface the persistence layer had nothing to ask, so it read
 * `definition.policy.key` directly and every type that keeps its rule
 * anywhere else mirrored the wrong value: a composite keeps its rule under
 * `aggregation`, deliberately, because a part carries a `policy` of its own,
 * so a Kprime grid graded under `subset_threshold` while the column recorded
 * `composite_weighted` and the band table never reached `scoring_params`.
 *
 * `PolicyBackedScoring` implements it, so every type already using that trait
 * satisfies it for free and a vendor container type that keeps its rule
 * somewhere else has an interface to implement rather than a core edit to
 * request.
 */
interface PolicyReferenceDeclaringInterface
{
    /**
     * The top-level definition key this type keeps its marking rule under.
     *
     * "policy" for almost everything, and "aggregation" for a container
     * type, whose parts each carry a "policy" of their own, so one word for
     * two rules is how the wrong one gets read. Anything that writes a rule
     * into a definition asks here rather than assuming: the authoring picker
     * seeded every composite with a stray "policy" field its own schema
     * forbids and its own type ignores, and the save path stamped a default
     * into the same place.
     */
    public function policyField(): string;

    /**
     * The policy key in force for this definition, wherever this type keeps
     * it and whatever legacy pin applies when the definition names none.
     *
     * @param array<string, mixed> $definition
     */
    public function policyKeyFor(array $definition): string;

    /**
     * The parameters that policy will actually run with, defaults folded in.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function policyParamsFor(array $definition, ScoringPolicyInterface $policy): array;
}
