<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityLeaf;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityNode;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTree;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionInterface;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Availability\BatchEvaluatesItems;
use Shondalai\VidyaLMS\Core\Registry\Availability\ConditionEvaluationRequest;
use Shondalai\VidyaLMS\Core\Registry\Availability\DeclaresExhaustion;
use Shondalai\VidyaLMS\Core\Registry\Availability\DeclaresReferences;
use Shondalai\VidyaLMS\Core\Registry\Availability\FiltersLearners;
use Shondalai\VidyaLMS\Core\Registry\Availability\RequiresFacts;
use Shondalai\VidyaLMS\Core\Registry\Availability\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;

/**
 * Evaluates one or many restriction trees against one learner, in the three
 * phases the query budget depends on.
 *
 * Phase one walks every tree in the render set, collects leaves grouped by
 * provider key and loads the union of the fact keys those leaves declare.
 * Phase two issues exactly one evaluateMany() call per provider that declares
 * BatchEvaluatesItems and one evaluate() per leaf for those that do not.
 * Phase three folds.
 *
 * Short-circuiting applies to the boolean answer only, and is therefore free
 * once every leaf is already answered. A composer that short-circuited the
 * evaluation itself could not satisfy the query budget, because it cannot
 * know which leaves it will need until it has evaluated them, and it could
 * not compute the opening instant either: under an "and" node that is the
 * maximum across the blocking children and cannot be computed from a prefix.
 *
 * Two registry rules are enforced here rather than trusted to providers. A
 * NEVER from a provider that does not implement FiltersLearners is downgraded
 * to BLOCKED and logged with the provider key, because a silently corrupted
 * completion denominator is the one failure in this design that is invisible
 * until credentials are wrong. An exhausted flag from a provider that does
 * not declare DeclaresExhaustion is ignored and logged, for the same reason
 * read from the other side.
 */
final class AvailabilityComposer
{
    /** What a leaf answers when its provider is missing or cannot read the payload. */
    public const REASON_PROVIDER_MISSING = 'availability.provider_missing';

    public function __construct(
        private readonly AvailabilityConditionRegistry $conditions,
        private readonly AvailabilityFactLoaders $factLoaders,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * One tree, one answer. The single-subject path still runs all three
     * phases, so a provider only ever sees the batched shape.
     */
    public function evaluate(AvailabilityTree $tree, AvailabilityContext $context): AvailabilityDecision
    {
        $subject = $context->subject;
        $key = $subject === null ? 'subject' : $subject->key();
        $decisions = $this->evaluateSet([$key => new SubjectTree($tree, $subject)], $context);

        return $decisions[$key] ?? AvailabilityDecision::open();
    }

    /**
     * Many trees, one learner, one pass.
     *
     * @param array<string, SubjectTree> $subjects
     *
     * @return array<string, AvailabilityDecision>
     */
    public function evaluateSet(array $subjects, AvailabilityContext $context): array
    {
        $collected = $this->collect($subjects);

        if ($collected === []) {
            $open = [];

            foreach (array_keys($subjects) as $key) {
                $open[$key] = AvailabilityDecision::open();
            }

            return $open;
        }

        $context = $this->withPreloadedFacts($collected, $subjects, $context);
        $outcomes = $this->dispatch($collected, $context);

        $decisions = [];

        foreach ($subjects as $key => $subjectTree) {
            $root = $subjectTree->tree->root;

            if ($root === null || $root->children === []) {
                $decisions[$key] = AvailabilityDecision::open();
                continue;
            }

            $decisions[$key] = $this->fold($root, false, $key, '', $outcomes);
        }

        return $decisions;
    }

    /**
     * Phase one: every leaf in the render set, keyed by "subjectKey|leafPath"
     * so a batching provider gets one call for all of them.
     *
     * @param array<string, SubjectTree> $subjects
     *
     * @return array<string, array<string, CollectedLeaf>> provider key to leaves
     */
    private function collect(array $subjects): array
    {
        $collected = [];

        foreach ($subjects as $subjectKey => $subjectTree) {
            foreach ($this->walk($subjectTree->tree, $subjectKey) as $leafKey => $leaf) {
                $collected[$leaf->leaf->type][$leafKey] = new CollectedLeaf(
                    $leaf->leaf,
                    $leaf->negated,
                    $subjectTree->subject,
                );
            }
        }

        return $collected;
    }

    /**
     * @return array<string, WalkedLeaf>
     */
    private function walk(AvailabilityTree $tree, string $subjectKey): array
    {
        if ($tree->root === null) {
            return [];
        }

        $leaves = [];
        $this->walkNode($tree->root, false, $subjectKey, '', $leaves);

        return $leaves;
    }

    /**
     * @param array<string, WalkedLeaf> $leaves
     */
    private function walkNode(
        AvailabilityNode $node,
        bool $parentNegated,
        string $subjectKey,
        string $path,
        array &$leaves,
    ): void {
        $negated = $parentNegated !== $node->isNegated();

        foreach ($node->children as $index => $child) {
            $childPath = ($path === '' ? '' : $path . '.') . 'c.' . $index;

            if ($child instanceof AvailabilityNode) {
                $this->walkNode($child, $negated, $subjectKey, $childPath, $leaves);
                continue;
            }

            $leaves[$subjectKey . '|' . $childPath] = new WalkedLeaf($child, $negated);
        }
    }

    /**
     * @param array<string, array<string, CollectedLeaf>> $collected
     * @param array<string, SubjectTree>                  $subjects
     */
    private function withPreloadedFacts(array $collected, array $subjects, AvailabilityContext $context): AvailabilityContext
    {
        $factKeys = [];

        foreach (array_keys($collected) as $providerKey) {
            if (!$this->conditions->has($providerKey)) {
                continue;
            }

            $condition = $this->conditions->get($providerKey);

            if (!$condition instanceof RequiresFacts) {
                continue;
            }

            foreach ($condition->requiredFacts() as $factKey) {
                $factKeys[$factKey] = true;
            }
        }

        $courseIds = [];
        $courseId = $context->courseId();

        if ($courseId !== null) {
            $courseIds[$courseId] = true;
        }

        $batchHint = max(1, \count($subjects));
        $context = AvailabilityContext::create(
            $context->principal,
            $context->surface,
            $context->now,
            $context->enrolment,
            $context->course,
            $context->subject,
            $context->timezoneChain,
            $batchHint,
        );

        return $context->withFacts(
            $this->factLoaders->loadAll(
                array_keys($factKeys),
                $context,
                array_keys($courseIds),
                $this->subjectKeysOf($collected, $subjects),
            ),
        );
    }

    /**
     * Every entity this render touches, as "type:id": the subjects the trees
     * hang on plus the referents the leaves name.
     *
     * A subject-aware loader is handed both halves because they are not the
     * same set. A cohort schedule leaf on the week-three quiz may name the
     * module it belongs to, and a loader given only the render's own subjects
     * would answer nothing for it and the gate would fail closed for a reason
     * that is the loader's rather than the site's.
     *
     * @param array<string, array<string, CollectedLeaf>> $collected
     * @param array<string, SubjectTree>                  $subjects
     *
     * @return list<string>
     */
    private function subjectKeysOf(array $collected, array $subjects): array
    {
        $keys = [];

        foreach ($subjects as $entry) {
            if ($entry->subject !== null) {
                $keys[$entry->subject->key()] = true;
            }
        }

        foreach ($collected as $providerKey => $leaves) {
            if (!$this->conditions->has($providerKey)) {
                continue;
            }

            $condition = $this->conditions->get($providerKey);

            if (!$condition instanceof DeclaresReferences) {
                continue;
            }

            foreach ($leaves as $leaf) {
                foreach ($condition->dependsOn($leaf->leaf->payload) as $reference) {
                    $keys[$reference->key()] = true;
                }
            }
        }

        return array_keys($keys);
    }

    /**
     * Phase two: one call per batching provider, one call per leaf otherwise.
     *
     * @param array<string, array<string, CollectedLeaf>> $collected
     *
     * @return array<string, AvailabilityOutcome>
     */
    private function dispatch(array $collected, AvailabilityContext $context): array
    {
        $outcomes = [];

        foreach ($collected as $providerKey => $leaves) {
            $condition = $this->resolve($providerKey);

            if ($condition === null) {
                foreach (array_keys($leaves) as $leafKey) {
                    $outcomes[$leafKey] = $this->providerMissing($providerKey);
                }

                continue;
            }

            [$readable, $unreadable] = $this->splitByPayloadVersion($condition, $leaves);

            foreach (array_keys($unreadable) as $leafKey) {
                $outcomes[$leafKey] = $this->providerMissing($providerKey);
            }

            if ($readable === []) {
                continue;
            }

            if ($condition instanceof BatchEvaluatesItems) {
                $requests = [];

                foreach ($readable as $leafKey => $collectedLeaf) {
                    $requests[$leafKey] = new ConditionEvaluationRequest(
                        $collectedLeaf->leaf->payload,
                        $collectedLeaf->negated,
                        $collectedLeaf->subject,
                    );
                }

                $answered = $condition->evaluateMany($requests, $context);

                foreach ($readable as $leafKey => $collectedLeaf) {
                    $outcomes[$leafKey] = $this->guard(
                        $condition,
                        $answered[$leafKey] ?? $this->providerMissing($providerKey),
                    );
                }

                continue;
            }

            foreach ($readable as $leafKey => $collectedLeaf) {
                $outcomes[$leafKey] = $this->guard($condition, $condition->evaluate(
                    $collectedLeaf->leaf->payload,
                    $collectedLeaf->negated,
                    $context->forSubject($collectedLeaf->subject),
                ));
            }
        }

        return $outcomes;
    }

    /**
     * A resolvable key carrying an unsupported payload version is a miss and
     * is treated identically to a missing provider. A plain has() check
     * cannot see it: a paid package decorating a free condition means
     * removing the package re-registers the free provider under the same key,
     * get() resolves, and a payload written by the paid version reaches an
     * evaluator that cannot read its own fields.
     *
     * @param array<string, CollectedLeaf> $leaves
     *
     * @return array{0: array<string, CollectedLeaf>, 1: array<string, CollectedLeaf>}
     */
    private function splitByPayloadVersion(AvailabilityConditionInterface $condition, array $leaves): array
    {
        $supported = $condition instanceof SupportsPayloadVersions
            ? $condition->supportedPayloadVersions()
            : [1];

        $current = $condition->currentShape();
        $readable = [];
        $unreadable = [];

        foreach ($leaves as $leafKey => $leaf) {
            if (\in_array($leaf->leaf->payloadVersion, $supported, true)) {
                $readable[$leafKey] = $leaf;
                continue;
            }

            // The provider's own upgrader chain, on read, in memory.
            //
            // Every free condition implements upgrade() and nothing called
            // it, so a leaf stored below the provider's current shape routed
            // straight to the miss path and failed closed forever: the first
            // provider to bump its shape would have had to support version 1
            // for the life of the product. A read never writes, so the
            // upgraded payload is used for this evaluation and the stored row
            // is left exactly as it is; the batch job is what rewrites it.
            if ($leaf->leaf->payloadVersion < $current) {
                $upgraded = $this->upgraded($condition, $leaf, $current);

                if ($upgraded !== null) {
                    $readable[$leafKey] = $upgraded;
                    continue;
                }
            }

            $this->logger->warning('VidyaLMS availability leaf payload version is unsupported.', [
                'provider' => $condition->key(),
                'payload_version' => $leaf->leaf->payloadVersion,
                'supported' => $supported,
            ]);

            $unreadable[$leafKey] = $leaf;
        }

        return [$readable, $unreadable];
    }

    /**
     * One leaf stepped up its provider's own chain, or null when the chain
     * cannot be completed.
     *
     * A partial chain is never applied and the payload is left exactly as
     * stored, which is the rule the definition upgrader contract already
     * gives: an upgrade that stopped halfway would hand a provider a shape
     * neither version can read.
     */
    private function upgraded(AvailabilityConditionInterface $condition, CollectedLeaf $leaf, int $current): ?CollectedLeaf
    {
        $payload = $leaf->leaf->payload;
        $from = $leaf->leaf->payloadVersion;

        try {
            while ($from < $current) {
                $payload = $condition->upgrade(
                    $payload,
                    $from,
                    UpgradeContext::create($condition->key(), $from),
                );
                ++$from;
            }
        } catch (\Throwable $failure) {
            $this->logger->warning('VidyaLMS availability leaf upgrade failed; the leaf is treated as unreadable.', [
                'provider' => $condition->key(),
                'from' => $leaf->leaf->payloadVersion,
                'to' => $current,
                'exception' => $failure,
            ]);

            return null;
        }

        return new CollectedLeaf(
            new AvailabilityLeaf($leaf->leaf->type, $payload, $current),
            $leaf->negated,
            $leaf->subject,
        );
    }

    private function resolve(string $providerKey): ?AvailabilityConditionInterface
    {
        if (!$this->conditions->has($providerKey)) {
            $this->logger->warning('VidyaLMS availability condition provider is missing.', [
                'provider' => $providerKey,
            ]);

            return null;
        }

        return $this->conditions->get($providerKey);
    }

    private function providerMissing(string $providerKey): AvailabilityOutcome
    {
        // The gate fails closed, with no opening instant. Getting this
        // direction wrong opens paid content the day a licence lapses.
        return AvailabilityOutcome::blocked(self::REASON_PROVIDER_MISSING, ['provider' => $providerKey]);
    }

    /**
     * The two registry rules the composer owns rather than trusting.
     */
    private function guard(AvailabilityConditionInterface $condition, AvailabilityOutcome $outcome): AvailabilityOutcome
    {
        if ($outcome->isNever() && !$condition instanceof FiltersLearners) {
            $this->logger->warning('VidyaLMS availability condition returned NEVER without FiltersLearners.', [
                'provider' => $condition->key(),
            ]);

            $outcome = $outcome->downgradedToBlocked();
        }

        if ($outcome->exhausted && !$condition instanceof DeclaresExhaustion) {
            $this->logger->warning('VidyaLMS availability condition set exhausted without DeclaresExhaustion.', [
                'provider' => $condition->key(),
            ]);

            $outcome = $outcome->withoutExhaustion();
        }

        return $outcome;
    }

    /**
     * Phase three: the fold, stated exactly.
     *
     * @param array<string, AvailabilityOutcome> $outcomes
     */
    private function fold(
        AvailabilityNode $node,
        bool $parentNegated,
        string $subjectKey,
        string $path,
        array $outcomes,
    ): AvailabilityDecision {
        $negated = $parentNegated !== $node->isNegated();
        $operator = $this->effectiveOperator($node, $negated);

        /** @var list<array{decision: AvailabilityDecision, shown: bool}> $children */
        $children = [];

        foreach ($node->children as $index => $child) {
            $childPath = ($path === '' ? '' : $path . '.') . 'c.' . $index;

            $decision = $child instanceof AvailabilityNode
                ? $this->fold($child, $negated, $subjectKey, $childPath, $outcomes)
                : new AvailabilityDecision(
                    $outcomes[$subjectKey . '|' . $childPath] ?? $this->providerMissing($child->type),
                );

            $children[] = ['decision' => $decision, 'shown' => $node->showsChild($index) && $decision->visible];
        }

        if ($children === []) {
            return AvailabilityDecision::open();
        }

        return $operator === AvailabilityNode::OP_AND
            ? $this->foldAnd($children)
            : $this->foldOr($children);
    }

    private function effectiveOperator(AvailabilityNode $node, bool $negated): string
    {
        $base = ($node->op === AvailabilityNode::OP_AND || $node->op === AvailabilityNode::OP_NOT_AND)
            ? AvailabilityNode::OP_AND
            : AvailabilityNode::OP_OR;

        if (!$negated) {
            return $base;
        }

        return $base === AvailabilityNode::OP_AND ? AvailabilityNode::OP_OR : AvailabilityNode::OP_AND;
    }

    /**
     * @param list<array{decision: AvailabilityDecision, shown: bool}> $children
     */
    private function foldAnd(array $children): AvailabilityDecision
    {
        $failing = [];

        foreach ($children as $child) {
            if (!$child['decision']->isAvailable()) {
                $failing[] = $child;
            }
        }

        if ($failing === []) {
            return AvailabilityDecision::open();
        }

        foreach ($failing as $child) {
            if ($child['decision']->outcome->isNever()) {
                return new AvailabilityDecision($child['decision']->outcome, $child['shown']);
            }
        }

        $opensAt = null;
        $opensAtTimezone = null;
        $namesAll = true;
        $exhausted = false;

        foreach ($failing as $child) {
            $outcome = $child['decision']->outcome;
            $exhausted = $exhausted || $outcome->exhausted;

            if ($outcome->opensAt === null) {
                $namesAll = false;
                continue;
            }

            // The maximum, because a node whose children open on 3 March and
            // 10 March opens on 10 March.
            if ($opensAt === null || $outcome->opensAt > $opensAt) {
                $opensAt = $outcome->opensAt;
                $opensAtTimezone = $outcome->opensAtTimezone;
            }
        }

        if (!$namesAll) {
            $opensAt = null;
            $opensAtTimezone = null;
        }

        return $this->spokesman($failing, $opensAt, $opensAtTimezone, $exhausted);
    }

    /**
     * @param list<array{decision: AvailabilityDecision, shown: bool}> $children
     */
    private function foldOr(array $children): AvailabilityDecision
    {
        foreach ($children as $child) {
            if ($child['decision']->isAvailable()) {
                return AvailabilityDecision::open();
            }
        }

        $allNever = true;

        foreach ($children as $child) {
            if (!$child['decision']->outcome->isNever()) {
                $allNever = false;
                break;
            }
        }

        if ($allNever) {
            return new AvailabilityDecision($children[0]['decision']->outcome, $children[0]['shown']);
        }

        $opensAt = null;
        $opensAtTimezone = null;
        $namesAll = true;
        $exhausted = true;

        foreach ($children as $child) {
            $outcome = $child['decision']->outcome;

            // An or node is exhausted only when every child is exhausted or
            // never: one child that can still open reopens the whole node.
            $exhausted = $exhausted && ($outcome->exhausted || $outcome->isNever());

            if ($outcome->isNever()) {
                continue;
            }

            if ($outcome->opensAt === null) {
                $namesAll = false;
                continue;
            }

            if ($opensAt === null || $outcome->opensAt < $opensAt) {
                $opensAt = $outcome->opensAt;
                $opensAtTimezone = $outcome->opensAtTimezone;
            }
        }

        if (!$namesAll) {
            $opensAt = null;
            $opensAtTimezone = null;
        }

        return $this->spokesman($children, $opensAt, $opensAtTimezone, $exhausted);
    }

    /**
     * The reason a node reports is the first failing child that is shown, and
     * the node is hidden when no failing child is.
     *
     * @param list<array{decision: AvailabilityDecision, shown: bool}> $failing
     */
    private function spokesman(
        array $failing,
        ?\DateTimeImmutable $opensAt,
        ?string $opensAtTimezone,
        bool $exhausted,
    ): AvailabilityDecision {
        $visible = false;
        $spokesman = $failing[0]['decision']->outcome;

        foreach ($failing as $child) {
            if ($child['shown']) {
                $visible = true;
                $spokesman = $child['decision']->outcome;
                break;
            }
        }

        return new AvailabilityDecision(
            AvailabilityOutcome::blocked(
                $spokesman->reasonKey ?? 'availability.blocked',
                $spokesman->reasonParameters,
                $opensAt,
                $opensAtTimezone,
                $exhausted,
                $spokesman->unlockAction,
            ),
            $visible,
        );
    }
}
