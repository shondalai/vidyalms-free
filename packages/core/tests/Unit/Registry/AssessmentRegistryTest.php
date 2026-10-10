<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\Seed;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseValue;
use Shondalai\VidyaLMS\Core\Provider\AttemptPolicy\DeferredFeedbackAttemptPolicy;
use Shondalai\VidyaLMS\Core\Provider\AttemptPolicy\ManualAttemptPolicy;
use Shondalai\VidyaLMS\Core\Provider\Interaction\ArtifactPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\AssociatePrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\OrderPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\PointPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\ScalarPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\SelectPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\SignalPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\TextPrimitive;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\FillBlankQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\MultipleChoiceQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\OrderingQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\ShortAnswerQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\SingleChoiceQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\TrueFalseQuestionType;
use Shondalai\VidyaLMS\Core\Provider\Scoring\FreeScoringPolicies;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyContext;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\ScoringContext;
use Shondalai\VidyaLMS\Core\Registry\DefinitionUpgrader\DefinitionUpgraderRegistry;
use Shondalai\VidyaLMS\Core\Registry\Interaction\InteractionPrimitiveRegistry;
use Shondalai\VidyaLMS\Core\Registry\Interop\ItemMapperRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionContractInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PolicyResolution;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PrimitiveComposingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ScoringPolicyAwareInterface;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;
use Shondalai\VidyaLMS\Core\Registry\Scoring\UnresolvedScoringPolicy;
use Shondalai\VidyaLMS\Core\Tests\Support\AnyShapeDecoratorPolicy;
use Shondalai\VidyaLMS\Core\Tests\Support\StubDefinitionUpgrader;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

/**
 * Every registry defines its miss, not only the type registry, and the free
 * build's own providers must satisfy the rule that binds them together.
 */
final class AssessmentRegistryTest extends TestCase
{
    /**
     * @return list<\Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface>
     */
    private function freeTypes(): array
    {
        $translator = new StubTranslator();

        return [
            new SingleChoiceQuestionType($translator),
            new MultipleChoiceQuestionType($translator),
            new TrueFalseQuestionType($translator),
            new ShortAnswerQuestionType($translator),
            new FillBlankQuestionType($translator),
            new OrderingQuestionType($translator),
        ];
    }

    private function primitives(): InteractionPrimitiveRegistry
    {
        $registry = new InteractionPrimitiveRegistry();
        $registry->register(new SelectPrimitive());
        $registry->register(new OrderPrimitive());
        $registry->register(new AssociatePrimitive());
        $registry->register(new TextPrimitive());
        $registry->register(new ScalarPrimitive());
        $registry->register(new PointPrimitive());
        $registry->register(new ArtifactPrimitive());
        $registry->register(new SignalPrimitive());

        return $registry;
    }

    // ── The rule that keeps a free site resolvable ───────────────────────

    /**
     * A free type's default policy, its legacy default and every policy key
     * a free-build migration writes must all name a free provider. The
     * alternative is a free site resolving a key the registry does not
     * contain.
     */
    public function testEveryFreeTypesDefaultAndLegacyPolicyIsAFreeProvider(): void
    {
        $free = FreeScoringPolicies::keys();

        foreach ($this->freeTypes() as $type) {
            self::assertInstanceOf(ScoringPolicyAwareInterface::class, $type);
            self::assertContains($type->defaultPolicy(), $free, $type->key() . ' default');

            $legacy = $type->legacyPolicy();

            if ($legacy !== null && $legacy !== '') {
                self::assertContains($legacy, $free, $type->key() . ' legacy pin');
            }

            foreach ($type->supportedPolicies() as $policy) {
                self::assertContains($policy, $free, $type->key() . ' supported list');
            }
        }
    }

    /**
     * The two keys the free-build migration writes into
     * questions.scoring_policy.
     */
    public function testTheMigrationWrittenKeysAreFreeProviders(): void
    {
        self::assertContains('plus_minus', FreeScoringPolicies::keys());
        self::assertContains('absolute_position', FreeScoringPolicies::keys());
    }

    /**
     * Precedence: the policy-side shape binding is authoritative, and a
     * type's supported list may only narrow it, never widen it.
     */
    public function testATypeNeverOffersAPolicyItsShapeDoesNotSupport(): void
    {
        $policies = FreeScoringPolicies::registry();

        foreach ($this->freeTypes() as $type) {
            self::assertInstanceOf(ScoringPolicyAwareInterface::class, $type);
            self::assertInstanceOf(DefinitionContractInterface::class, $type);

            $shape = $type->responseShape();

            foreach ($type->supportedPolicies() as $key) {
                $bindings = $policies->get($key)->appliesToShapes();

                if ($bindings === []) {
                    // A decorator over any shape is applicable wherever its
                    // base policy is, by design.
                    continue;
                }

                $compatible = false;

                foreach ($bindings as $binding) {
                    $compatible = $compatible || $binding->isCompatibleWith($shape);
                }

                self::assertTrue(
                    $compatible,
                    sprintf('%s offers %s, which does not bind to %s', $type->key(), $key, $shape->key()),
                );
            }
        }
    }

    /**
     * The other half of the same rule, and the half nothing tested.
     *
     * supportedPolicies() may only narrow the shape-bound set, never widen it,
     * so a policy declaring itself a decorator over shape "any" is applicable
     * wherever its base policy is supported without every shipped catalogue
     * entry being edited to list it. Read as an exclusive allowlist, none of
     * Pro's any-shape decorators (certainty weighting, the two rubric
     * policies, an outcome lookup) could be selected on any free type without
     * Pro editing free-build files, which ADR 0002 forbids outright, and a
     * human-marked single choice was unauthorable for the same reason.
     */
    public function testAnAnyShapeDecoratorRegisteredAfterBootIsAuthorableOnEveryFreeType(): void
    {
        $policies = FreeScoringPolicies::registry();
        $policies->register(new AnyShapeDecoratorPolicy());

        foreach ($this->freeTypes() as $type) {
            self::assertInstanceOf(ScoringPolicyAwareInterface::class, $type);
            self::assertInstanceOf(DefinitionContractInterface::class, $type);

            self::assertContains(
                AnyShapeDecoratorPolicy::KEY,
                PolicyResolution::offerable($policies, $type->responseShape(), $type->supportedPolicies()),
                sprintf('%s cannot offer a decorator that binds to any shape', $type->key()),
            );

            PolicyResolution::validateReference(
                ['policy' => ['key' => AnyShapeDecoratorPolicy::KEY]],
                $policies,
                $type->responseShape(),
                $type->supportedPolicies(),
            );
        }

        // The narrowing direction still holds: a shape-bound policy is not
        // offerable on a type whose shape it does not bind to.
        $singleChoice = $this->freeTypes()[0];
        self::assertInstanceOf(ScoringPolicyAwareInterface::class, $singleChoice);
        self::assertInstanceOf(DefinitionContractInterface::class, $singleChoice);
        self::assertNotContains(
            'kendall_tau',
            PolicyResolution::offerable(
                $policies,
                $singleChoice->responseShape(),
                $singleChoice->supportedPolicies(),
            ),
        );
    }

    public function testEveryFreeTypeCanBeAuthoredAsHumanMarked(): void
    {
        $policies = FreeScoringPolicies::registry();

        foreach ($this->freeTypes() as $type) {
            self::assertInstanceOf(ScoringPolicyAwareInterface::class, $type);
            self::assertInstanceOf(DefinitionContractInterface::class, $type);

            self::assertContains(
                'external',
                PolicyResolution::offerable($policies, $type->responseShape(), $type->supportedPolicies()),
                sprintf('%s cannot be authored for a human marker', $type->key()),
            );
        }
    }

    public function testEveryFreeTypeNamesAPrimitiveTheRegistryHas(): void
    {
        $primitives = $this->primitives();

        foreach ($this->freeTypes() as $type) {
            self::assertInstanceOf(PrimitiveComposingInterface::class, $type);
            self::assertNotNull(
                $primitives->resolve($type->interactionPrimitive()),
                $type->key() . ' names an unknown primitive',
            );
        }
    }

    public function testEveryFreeTypeDeclaresAGroupAndAnOrder(): void
    {
        $seen = [];

        foreach ($this->freeTypes() as $type) {
            self::assertInstanceOf(
                \Shondalai\VidyaLMS\Core\Registry\QuestionType\ReportingMetadataInterface::class,
                $type,
            );
            $seen[$type->group() . ':' . $type->order()] = $type->key();
        }

        // Registration order is not picker order, so the declared pairs must
        // be unique or two types would sort arbitrarily against each other.
        self::assertCount(6, $seen);
    }

    // ── Registry misses ──────────────────────────────────────────────────

    public function testAnUnresolvedScoringPolicyScoresNothingRatherThanWrong(): void
    {
        $registry = FreeScoringPolicies::registry();
        $policy = $registry->resolve('certainty_weighted');

        self::assertInstanceOf(UnresolvedScoringPolicy::class, $policy);

        $result = $policy->score(
            ResponseValue::null(ResponseShape::of(
                \Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality::Single,
                \Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType::Identifier,
            )),
            ScoringKey::value('a'),
            [],
            ScoringContext::defaults(),
        );

        self::assertSame(\Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus::NotScored, $result->status);
        self::assertSame('certainty_weighted', $result->params['policy']);
    }

    public function testAnUnresolvedAttemptPolicyFallsBackAndRecordsTheSubstitution(): void
    {
        $translator = new StubTranslator();
        $registry = new AttemptPolicyRegistry();
        $registry->register(new DeferredFeedbackAttemptPolicy($translator));
        $registry->register(new ManualAttemptPolicy($translator));

        self::assertTrue($registry->isSubstituting('interactive_multi_try'));

        $behaviour = $registry->resolve('interactive_multi_try');
        self::assertSame(AttemptPolicyRegistry::DEFERRED_FEEDBACK, $behaviour->key());

        $set = $behaviour
            ->resolve([], AttemptPolicyContext::create(1, 1, Seed::of('s'), new \DateTimeImmutable('2026-01-01')))
            ->substitutedFrom('interactive_multi_try');

        self::assertSame('interactive_multi_try', $set->toArray()['substituted_from']);
    }

    public function testAnIncompleteUpgradeChainLeavesTheDefinitionAsStored(): void
    {
        $registry = new DefinitionUpgraderRegistry();
        $registry->register(new StubDefinitionUpgrader('x_acme_thing', 1, 2));
        // 2 to 3 is missing, so the chain from 1 to 3 cannot be completed.

        self::assertSame([], $registry->chainFor('x_acme_thing', 1, 3));
        self::assertSame(
            ['untouched' => true],
            $registry->upgrade('x_acme_thing', ['untouched' => true], 1, 3),
        );
    }

    public function testACompleteChainRunsEveryStepInOrder(): void
    {
        $registry = new DefinitionUpgraderRegistry();
        $registry->register(new StubDefinitionUpgrader('x_acme_thing', 1, 2));
        $registry->register(new StubDefinitionUpgrader('x_acme_thing', 2, 3));

        $upgraded = $registry->upgrade('x_acme_thing', ['steps' => []], 1, 3);

        self::assertSame([[1, 2], [2, 3]], $upgraded['steps']);
    }

    public function testAnUnresolvedMapperIsSimplyAbsent(): void
    {
        $registry = new ItemMapperRegistry();

        self::assertNull($registry->resolve('qti3'));
        self::assertSame([], $registry->formats('import'));
    }

    public function testTheFreeBuildRegistersAllEightPrimitives(): void
    {
        self::assertSame(
            ['select', 'order', 'associate', 'text', 'scalar', 'point', 'artifact', 'signal'],
            $this->primitives()->keys(),
        );
    }

    public function testEveryPrimitiveDeclaresAtLeastOneSurfaceAndOneShape(): void
    {
        foreach ($this->primitives()->all() as $primitive) {
            self::assertNotSame([], $primitive->surfaces(), $primitive->key());
            self::assertNotSame([], $primitive->shapes(), $primitive->key());
        }
    }
}
