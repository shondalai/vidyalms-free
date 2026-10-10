<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Scoring;

/**
 * A policy that wraps another one, probed with instanceof like every other
 * optional capability.
 *
 * The design's precedence rule reads in full: the policy-side shape binding
 * is authoritative for what may be offered, a type's supportedPolicies() may
 * only narrow that set, and a decorator declaring shape `any` is applicable
 * **wherever its base policy is supported**. Without a way to ask a policy
 * what it decorates, the last clause cannot be evaluated, so every any-shape
 * policy became applicable everywhere: certainty weighting was offerable on
 * an essay whose only supported rule is external marking, and marked an
 * ungraded upload as fully correct with a final session status.
 *
 * Declaring this interface is what lets a type narrow a decorator away
 * without any core edit and without an allowlist per type, which is what
 * makes certainty-based marking authorable on true or false (base
 * match_correct, which true_false supports) and refused on a file response
 * (base match_correct, which it does not).
 */
interface DecoratingPolicyInterface
{
    /**
     * The policy this one wraps when the item names nothing.
     */
    public function defaultBasePolicyKey(): string;

    /**
     * The policy this one wraps for the given parameters.
     *
     * @param array<string, mixed> $params
     */
    public function basePolicyKey(array $params): string;
}
