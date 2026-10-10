<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\Scoring\DecoratingPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Scoring\PolicyAvailabilityInterface;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;

/**
 * How a question type resolves the marking rule a definition names, and which
 * rules it may offer at all. Published API, under Registry rather than under
 * Provider.
 *
 * It lives here because Pro and any third-party type wanting the same
 * behaviour would otherwise have to couple to a free default provider's
 * internals, which are not contract surface and can change without notice, or
 * copy them. The rule this class owns decides whether a definition naming no
 * policy grades under the legacy pin or the current default, and that moves
 * marks, so two copies of it drifting is not a style problem.
 *
 * **The offerable set is computed, never a literal list.** The design states
 * the precedence once: the policy-side shape binding is authoritative for what
 * may be offered, and a type's supportedPolicies() may only narrow that set,
 * never widen it. A policy declaring itself a decorator over shape `any`
 * (certainty weighting, rubrics, an outcome lookup, external marking, none) is
 * therefore applicable wherever its base policy is supported, without every
 * shipped catalogue entry being edited to list it. Requiring that edit is the
 * fork the design forbids: as an exclusive allowlist, none of Pro's any-shape
 * decorators could be selected on any free type without Pro editing free-build
 * files, which ADR 0002 forbids outright.
 */
final class PolicyResolution
{
    /**
     * The key of the policy that scores nothing.
     *
     * Named here rather than read off the free build's NonePolicy, because a
     * use case asserting the design's "a question whose policy is none must
     * carry scored = 0" rule would otherwise depend on a default provider's
     * class. The key is published contract; the provider behind it is not.
     */
    /**
     * The definition key a type keeps its marking rule under, by default.
     *
     * A container type keeps its own under another name, so anything writing
     * a rule into a definition asks the type through
     * PolicyReferenceDeclaringInterface::policyField() rather than reaching
     * for this constant.
     */
    public const FIELD = 'policy';

    public const SCORES_NOTHING = 'none';

    /**
     * The key of the policy that hands the item to a human.
     *
     * Named here for the same reason SCORES_NOTHING is: the rule that a
     * decorator has nothing to scale on a type whose every rule is one of
     * these two is a contract rule, and reading it off a default provider's
     * class would make Registry depend on Provider.
     */
    public const MARKED_EXTERNALLY = 'external';

    /**
     * The rules that return no fraction of their own, so nothing can
     * decorate them.
     *
     * @var list<string>
     */
    private const PRODUCE_NO_FRACTION = [self::SCORES_NOTHING, self::MARKED_EXTERNALLY];

    private function __construct()
    {
    }

    /**
     * The policy key in force for a definition.
     *
     * A definition that names one under policy.key uses it. A definition that
     * names none was authored before the field existed, so it grades under the
     * legacy pin and no historic percentage moves; a type with no legacy
     * behaviour to pin says so with an empty string and falls through to its
     * default.
     *
     * @param array<string, mixed> $definition
     */
    public static function keyFor(array $definition, string $legacyPolicy, string $defaultPolicy): string
    {
        $policy = $definition['policy'] ?? null;

        if (\is_array($policy) && \is_string($policy['key'] ?? null) && $policy['key'] !== '') {
            return $policy['key'];
        }

        return $legacyPolicy === '' ? $defaultPolicy : $legacyPolicy;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public static function paramsFor(array $definition, ScoringPolicyInterface $policy): array
    {
        $declared = $definition['policy']['params'] ?? null;
        $params = \is_array($declared) ? $declared : [];

        /** @var array<string, mixed> $params */
        return $params + $policy->defaultParams();
    }

    /**
     * Every policy this site could offer for one response shape.
     *
     * @param list<string> $supported the type's own narrowing, empty for none
     *
     * @return list<string> registry order
     */
    public static function offerable(
        ScoringPolicyRegistry $policies,
        ?ResponseShape $shape,
        array $supported,
    ): array {
        $keys = [];

        foreach ($policies->all() as $policy) {
            if (self::permitsPolicy($policy, $shape, $supported)) {
                $keys[] = $policy->key();
            }
        }

        return $keys;
    }

    /**
     * Whether one policy key may be authored on a type of this shape.
     *
     * @param list<string> $supported
     */
    public static function permits(
        ScoringPolicyRegistry $policies,
        ?ResponseShape $shape,
        array $supported,
        string $key,
    ): bool {
        if (!$policies->has($key)) {
            return false;
        }

        return self::permitsPolicy($policies->get($key), $shape, $supported);
    }

    /**
     * Validates the policy reference a definition carries, so an author cannot
     * save a question naming a policy this site does not have, a policy that
     * does not bind to the type's shape, or parameters that belong somewhere
     * else.
     *
     * @param array<string, mixed> $definition
     * @param list<string>         $supported
     */
    public static function validateReference(
        array $definition,
        ScoringPolicyRegistry $policies,
        ?ResponseShape $shape,
        array $supported,
    ): void {
        $policy = $definition['policy'] ?? null;

        if ($policy === null) {
            return;
        }

        if (!\is_array($policy) || !\is_string($policy['key'] ?? null) || $policy['key'] === '') {
            throw ValidationException::forField('policy', 'A policy reference needs a string key.');
        }

        if ($policies->has($policy['key'])) {
            $named = $policies->get($policy['key']);

            // The reason names the missing feature rather than saying the key
            // is unknown, because it is not: the policy is registered, it just
            // cannot mark anything on this site yet.
            if ($named instanceof PolicyAvailabilityInterface && !$named->isAvailable()) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField('policy.key', $named->unavailableReason());
            }
        }

        if (!self::permits($policies, $shape, $supported, $policy['key'])) {
            throw ValidationException::forField(
                'policy.key',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('"%s" is not a scoring policy this question type offers.', $policy['key']),
            );
        }

        $params = $policy['params'] ?? [];

        if (!\is_array($params)) {
            throw ValidationException::forField('policy.params', 'Policy parameters must be an object.');
        }

        /** @var array<string, mixed> $params */
        $policies->resolve($policy['key'])->validateParams($params);
    }

    /**
     * @param list<string> $supported
     */
    private static function permitsPolicy(
        ScoringPolicyInterface $policy,
        ?ResponseShape $shape,
        array $supported,
    ): bool {
        // A policy waiting on a feature this site does not have is left out of
        // the offerable set entirely, so the authoring UI never shows a rule
        // that would mark every attempt not scored forever.
        if ($policy instanceof PolicyAvailabilityInterface && !$policy->isAvailable()) {
            return false;
        }

        $bindings = $policy->appliesToShapes();

        // A decorator over any shape applies wherever its base policy does,
        // and a type's own list cannot take it away: that list narrows the
        // shape-bound set, and a decorator is not in it.
        //
        // "Wherever its base policy is supported" is the whole clause, and
        // reading it as "wherever" alone is what let certainty weighting be
        // authored on a file response whose only rule is external marking,
        // then award full marks for no answer at all with a final session
        // status, in both directions at once.
        //
        // A decorator scales what a base produced. A type whose every
        // supported rule produces no fraction of its own (external marking,
        // or none) offers a decorator nothing to scale, so the decorator is
        // not applicable there. It says it is one through
        // DecoratingPolicyInterface, so this narrows without an allowlist
        // per type and without a core edit when a vendor ships one.
        if ($bindings === []) {
            if ($supported === [] || !$policy instanceof DecoratingPolicyInterface) {
                return true;
            }

            if (\in_array($policy->key(), $supported, true)) {
                return true;
            }

            return array_values(array_diff($supported, self::PRODUCE_NO_FRACTION)) !== [];
        }

        if ($supported !== [] && !\in_array($policy->key(), $supported, true)) {
            return false;
        }

        if ($shape === null) {
            return true;
        }

        foreach ($bindings as $binding) {
            if ($binding->isCompatibleWith($shape)) {
                return true;
            }
        }

        return false;
    }
}
