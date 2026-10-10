<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation\Identity;

use Shondalai\VidyaLMS\Core\Port\Identity\AssertedGroup;
use Shondalai\VidyaLMS\Core\Port\Identity\AssertedIdentity;
use Shondalai\VidyaLMS\Core\Port\Identity\CanonicalAttributes;

/**
 * The deterministic, total, deny-by-default evaluator.
 *
 * It is the one piece of this whole area that was prototyped before anything
 * else, because the design says so and the reason is sound: if the evaluator
 * produces the right decision on the four cases below, the rest is plumbing,
 * and if it does not, no amount of protocol correctness saves the feature.
 *
 * - a token whose group claim was omitted because the person is in too many
 *   groups, which must grant nothing and **remove nothing**;
 * - an assertion carrying group names, which must grant what the rule names;
 * - a directory push that removes one member, which is the only shape allowed
 *   to drive a removal, and only from a complete source on an authoritative
 *   connection;
 * - a plain host group read, which is what the free build actually runs.
 *
 * Three properties, and each is a rule rather than a quality:
 *
 * **Deterministic.** Rules are evaluated in stored order and the first rule
 * carrying stop ends the pass. The same assertion against the same rules
 * produces the same decision, which is what makes the explainer evidence.
 *
 * **Total.** Every assertion produces a decision. An unreadable rule set, an
 * unknown operator and a rule naming a role that no longer exists all produce
 * a recorded refusal and never an exception, because a login must not fail
 * because an administrator saved a rule badly a year ago.
 *
 * **Deny by default.** An asserted key matching no rule grants nothing and is
 * recorded as unmatched. That record is the difference between a rule set that
 * is wrong and a rule set that looks like it is working.
 */
final class MappingRuleEvaluator
{
    /**
     * @param bool $connectionAuthoritative whether the connection is marked authoritative, which
     *                                      only a site-scoped principal may set
     */
    public function evaluate(
        MappingRuleSet $rules,
        AssertedIdentity $identity,
        MappingBound $bound,
        bool $connectionAuthoritative,
    ): MappingDecision {
        [$mayRemove, $removalBasis] = $this->removalRule($identity, $connectionAuthoritative);

        if (!$rules->isReadable()) {
            return MappingDecision::of(
                $identity->issuer,
                $identity->subject,
                [],
                [],
                $this->keysOf($identity),
                [['rule' => -1, 'reason' => MappingDecision::REFUSED_UNREADABLE]],
                $mayRemove,
                $removalBasis,
            );
        }

        /** @var list<MappingGrant> $grants */
        $grants = [];
        /** @var list<array{rule: int, condition: string}> $fired */
        $fired = [];
        /** @var list<array{rule: int, reason: string}> $refusals */
        $refusals = [];
        /** @var array<string, true> $matchedKeys */
        $matchedKeys = [];

        foreach ($rules->rules as $index => $rule) {
            $matched = $this->firstMatch($rule, $identity, $refusals, $index);

            if ($matched === null) {
                continue;
            }

            if ($matched !== '') {
                $matchedKeys[$matched] = true;
            }

            $fired[] = ['rule' => $index, 'condition' => $rule->describe()];
            $refusal = $this->refusalFor($rule, $bound);

            if ($refusal !== null) {
                $refusals[] = ['rule' => $index, 'reason' => $refusal];

                // A refused rule still stops the pass when it says stop. The
                // author's intent was that nothing after it applies, and
                // honouring the intent only when the rule succeeds would make
                // a refusal widen access rather than narrow it.
                if ($rule->stop) {
                    break;
                }

                continue;
            }

            $grants[] = MappingGrant::of(
                $bound->organisationId,
                $rule->orgUnitId,
                $rule->roleKey,
                $index,
                $matched === '' ? null : $matched,
            );

            if ($rule->stop) {
                break;
            }
        }

        $unmatched = array_values(array_filter(
            $this->keysOf($identity),
            static fn (string $key): bool => !isset($matchedKeys[$key])
        ));

        // The second half of the removal rule, asked of what the pass could
        // actually read rather than only of what the source claimed about
        // itself. A pass that granted nothing and either could not compare a
        // key kind or recognised none of the keys it was given has produced no
        // evidence about this person at all.
        if ($mayRemove && $grants === [] && $this->readNothingComparable($refusals, $identity)) {
            $mayRemove = false;
            $removalBasis = MappingDecision::REMOVAL_EVIDENCE_UNREADABLE;
        }

        return MappingDecision::of(
            $identity->issuer,
            $identity->subject,
            $grants,
            $fired,
            $unmatched,
            $refusals,
            $mayRemove,
            $removalBasis,
        );
    }

    /**
     * The one rule that decides whether anything may be taken away.
     *
     * Both halves, and both are necessary. A complete source on a connection
     * nobody has vouched for is a tenant asserting authority over its own
     * removals; an authoritative connection carrying a truncated assertion is
     * the token size change that deletes a roster.
     *
     * @return array{bool, string}
     */
    private function removalRule(AssertedIdentity $identity, bool $connectionAuthoritative): array
    {
        // Delegated rather than repeated. The same two conditions were written
        // out here and again in MembershipRemovalGuard, which is the one place
        // the design says answers this question; two copies of one rule is one
        // copy away from two answers.
        return MembershipRemovalGuard::rule()->permitsFromAssertion($identity, $connectionAuthoritative);
    }

    /**
     * Whether the pass had anything it could compare at all.
     *
     * One shape, and it is narrow deliberately. A key_kind_mismatch refusal
     * says every asserted group was a different kind of key from the one the
     * rule asks about, so the comparison was never the same question: that is a
     * directory emitting names where it used to emit object ids, which is the
     * case the value_kind vocabulary exists for.
     *
     * A group list that simply matched no rule is **not** this case, and
     * treating it as one would break the design's own third fixture: a complete
     * authoritative source that no longer asserts the group which granted the
     * role is exactly how somebody leaves, and refusing removal there would
     * make the removal path unreachable.
     *
     * A source asserting no groups at all is not this case either: it is a
     * source saying this person is in nothing, which is a statement.
     *
     * @param list<array{rule: int, reason: string}> $refusals
     */
    private function readNothingComparable(array $refusals, AssertedIdentity $identity): bool
    {
        if ($identity->assertedGroups() === []) {
            return false;
        }

        foreach ($refusals as $refusal) {
            if ($refusal['reason'] === MappingDecision::REFUSED_KEY_KIND) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first asserted value this rule matches, or null.
     *
     * An empty string is the answer for a rule that matched on something other
     * than a group key, which has no key to name in the explainer.
     *
     * @param list<array{rule: int, reason: string}> $refusals
     */
    private function firstMatch(MappingRule $rule, AssertedIdentity $identity, array &$refusals, int $index): ?string
    {
        if ($rule->isGroupShaped()) {
            $sameKind = array_values(array_filter(
                $identity->assertedGroups(),
                static fn (AssertedGroup $group): bool => $group->isKind((string) $rule->valueKind)
            ));

            if ($sameKind === [] && $identity->assertedGroups() !== []) {
                // Every asserted group was a different kind of key, so the
                // comparison was never the same question. That is recorded as
                // a rule that did not apply, not as a rule that failed.
                $refusals[] = ['rule' => $index, 'reason' => MappingDecision::REFUSED_KEY_KIND];

                return null;
            }

            foreach ($sameKind as $group) {
                if (MappingOperator::satisfied($rule->operator, $group->key, $rule->value)) {
                    return $group->key;
                }
            }

            return null;
        }

        foreach ($this->valuesOf($rule->attribute, $identity) as $candidate) {
            if (MappingOperator::satisfied($rule->operator, $candidate, $rule->value)) {
                return '';
            }
        }

        return null;
    }

    /**
     * The candidate values for a non-group attribute.
     *
     * The two attributes that are the identity itself are answered from the
     * identity and not from the bag, because a mapper that forgot to copy them
     * into the bag would otherwise make a rule on the subject match nothing
     * rather than match the subject.
     *
     * @return list<string>
     */
    private function valuesOf(string $attribute, AssertedIdentity $identity): array
    {
        if ($attribute === CanonicalAttributes::SUBJECT) {
            return [$identity->subject];
        }

        if ($attribute === CanonicalAttributes::ISSUER) {
            return [$identity->issuer];
        }

        if ($attribute === CanonicalAttributes::EMAIL) {
            return $identity->email === null ? [] : [$identity->email];
        }

        if ($attribute === CanonicalAttributes::EMAIL_VERIFIED) {
            return [$identity->emailVerification];
        }

        $value = $identity->attributes[$attribute] ?? null;

        if (\is_string($value)) {
            return [$value];
        }

        if (!\is_array($value)) {
            return [];
        }

        $values = [];

        foreach ($value as $entry) {
            if (\is_string($entry)) {
                $values[] = $entry;
            }
        }

        return $values;
    }

    /**
     * Why this rule may not be applied, or null.
     *
     * The order is the order the damage runs in: a foreign organisation is the
     * cross-tenant grant, a foreign unit is the same thing one level down, and
     * a forbidden role is the escalation inside a tenant the rule is entitled
     * to touch.
     */
    private function refusalFor(MappingRule $rule, MappingBound $bound): ?string
    {
        if (!$bound->admitsOrganisation($rule->orgId)) {
            return MappingDecision::REFUSED_FOREIGN_ORG;
        }

        if (!$bound->admitsUnit($rule->orgUnitId)) {
            return MappingDecision::REFUSED_FOREIGN_UNIT;
        }

        if (!$bound->admitsRole($rule->roleKey)) {
            return MappingDecision::REFUSED_ROLE;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function keysOf(AssertedIdentity $identity): array
    {
        return array_values(array_unique(array_map(
            static fn (AssertedGroup $group): string => $group->key,
            $identity->assertedGroups()
        )));
    }
}
