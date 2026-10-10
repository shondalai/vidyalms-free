<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\QuestionType;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerValidity;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Surface;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Provider\Interaction\OrderPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Scoring\AbsolutePositionPolicy;
use Shondalai\VidyaLMS\Core\Provider\Scoring\KendallTauPolicy;
use Shondalai\VidyaLMS\Core\Provider\Scoring\OrderingMetric;
use Shondalai\VidyaLMS\Core\Registry\Context\DecodeContext;
use Shondalai\VidyaLMS\Core\Registry\Context\GradingContext;
use Shondalai\VidyaLMS\Core\Registry\Context\PresentationContext;
use Shondalai\VidyaLMS\Core\Registry\Interaction\InteractionPrimitiveInterface;
use Shondalai\VidyaLMS\Core\Registry\Interaction\InteractionPrimitiveRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\AnswerValidatingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ContextAwareGrading;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ContextAwarePresenting;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefaultDefinitionVersion;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionContractInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefinitionVersionAwareInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PrimitiveComposingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ReportingMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ScoringPolicyAwareInterface;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;

/**
 * Arranging items into a sequence: the order primitive on the flow surface.
 *
 * Definition shape:
 * {
 *   "prompt": string,
 *   "items": [{"key": string, "label": string, "fixed": optional bool}, ...],
 *   "correct": [string, ...], every item key exactly once, in the right order,
 *   "shuffle": optional bool, default true,
 *   "policy": optional {"key": string, "params": object},
 *   "feedback": optional string shown after grading
 * }
 *
 * Three corrections land here together, and every one of them changes a
 * mark, which is why they are free-build work that could not wait.
 *
 * **The permutation gate.** The response is validated as an exact
 * permutation of the presented item set before any policy runs. Without it
 * [A,A,A,A] against a key of [A,B,C,D] scores 0.25, a quarter of the marks
 * for a response that is not an ordering at all. The gate lives in the
 * primitive, so every ordering type gets it.
 *
 * **shuffle defaults to true.** An item authored with its steps already in
 * key order pays full marks to a learner who submits without touching it.
 *
 * **New items default to chance-corrected kendall_tau.** The expected number
 * of discordant pairs is exactly half the total for every n, so raw Kendall
 * tau pays a guesser 0.5 at every list length. Existing items are pinned to
 * absolute_position with the correction off, which is what they already
 * scored under, so no historic percentage moves.
 */
final class OrderingQuestionType implements
    QuestionTypeInterface,
    DefinitionContractInterface,
    DefinitionVersionAwareInterface,
    PrimitiveComposingInterface,
    ScoringPolicyAwareInterface,
    ReportingMetadataInterface,
    ContextAwarePresenting,
    ContextAwareGrading,
    AnswerValidatingInterface
{
    use DefaultDefinitionVersion;
    use PolicyBackedGrading;

    private readonly OrderPrimitive $primitive;

    public function __construct(
        private readonly TranslatorPort $translator,
        ?ScoringPolicyRegistry $policies = null,
    ) {
        $this->primitive = new OrderPrimitive();
        $this->freePolicyFallback = $policies;
    }

    public function key(): string
    {
        return 'ordering';
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.question_type.ordering');
    }

    public function group(): string
    {
        return ReportingMetadataInterface::GROUP_ASSOCIATION;
    }

    public function order(): int
    {
        return 10;
    }

    public function xapiInteractionType(): string
    {
        return 'sequencing';
    }

    public function qtiElement(): string
    {
        return 'qti-order-interaction';
    }

    public function supportedDefinitionVersions(): array
    {
        return [1];
    }

    public function supportedPolicies(): array
    {
        return [KendallTauPolicy::KEY, AbsolutePositionPolicy::KEY];
    }

    public function defaultPolicy(): string
    {
        return KendallTauPolicy::KEY;
    }

    public function legacyPolicy(): string
    {
        return AbsolutePositionPolicy::KEY;
    }

    /**
     * The parameters a legacy ordering definition grades under: exact
     * position with the chance correction off, which is exactly the formula
     * it graded under before.
     *
     * @return array<string, mixed>
     */
    public static function legacyPolicyParams(): array
    {
        return [OrderingMetric::PARAM_CHANCE_CORRECTED => false];
    }

    public function responseShape(): ResponseShape
    {
        return ResponseShape::of(Cardinality::Ordered, BaseType::Identifier);
    }

    public function definitionSchema(): array
    {
        return DefinitionRules::schema(
            self::class,
            [
                'prompt' => ['type' => 'string', 'minLength' => 1],
                'items' => DefinitionRules::keyedListSchema(withLabel: true),
                'correct' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
                'shuffle' => ['type' => 'boolean', 'default' => true],
                'orientation' => ['enum' => ['vertical', 'horizontal']],
                'policy' => DefinitionRules::policyRefSchema(),
                'feedback' => ['type' => 'string'],
                'scoring_intent' => ['enum' => ['machine', 'human']],
                'layout_classes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            ['prompt', 'items', 'correct'],
        );
    }

    public function presentableKeys(): array
    {
        return ['prompt', 'items', 'orientation', 'layout_classes'];
    }

    public function secretKeys(): array
    {
        return ['correct', 'feedback'];
    }

    public function exampleDefinition(): array
    {
        return [
            'prompt' => 'Sort them.',
            'items' => [
                ['key' => 'a', 'label' => 'First'],
                ['key' => 'b', 'label' => 'Second'],
            ],
            'correct' => ['b', 'a'],
            'feedback' => 'Well done.',
        ];
    }

    public function validateDefinition(array $definition): void
    {
        DefinitionRules::requirePrompt($definition);
        $keys = DefinitionRules::requireKeyedList($definition, 'items', withLabel: true);
        $correct = DefinitionRules::requireStringList($definition, 'correct', allowEmpty: false);
        $this->validatePolicyReference($definition);
        DefinitionRules::requireOptionalBoolField($definition, 'shuffle');

        $sortedKeys = $keys;
        $sortedCorrect = $correct;
        sort($sortedKeys);
        sort($sortedCorrect);

        if ($sortedKeys !== $sortedCorrect) {
            throw ValidationException::forField('correct', 'The correct order must contain every item key exactly once.');
        }

        DefinitionRules::requireOptionalStringField($definition, 'feedback');

        // Last, so the type's own cross-field rules keep naming their own
        // fields, and anything only the published schema knows about (an
        // unknown key, a value outside a declared enum) is still refused
        // rather than stored and read through a default branch.
        $this->validateAgainstSchema($definition);
    }

    public function presentForLearner(array $definition): array
    {
        return $this->presentForLearnerInContext($definition, PresentationContext::unseeded());
    }

    public function presentForLearnerInContext(array $definition, PresentationContext $context): array
    {
        $presented = [
            'prompt' => $definition['prompt'] ?? '',
            'items' => DefinitionRules::presentKeyedList($definition, 'items', $context->choiceSequence->for('items')),
        ];

        if (\is_string($definition['orientation'] ?? null)) {
            $presented['orientation'] = $definition['orientation'];
        }

        return $presented + DefinitionRules::presentConstraints($definition);
    }

    public function grade(array $definition, mixed $answer): GradeResult
    {
        return $this->gradeInContext($definition, $answer, GradingContext::detached());
    }

    public function gradeInContext(array $definition, mixed $answer, GradingContext $context): GradeResult
    {
        $correct = $definition['correct'] ?? [];
        /** @var list<string> $correct */
        $correct = \is_array($correct) && array_is_list($correct)
            ? array_values(array_filter($correct, '\is_string'))
            : [];

        return $this->scoreThrough(
            $definition,
            $answer,
            ScoringKey::sequence($correct, DefinitionRules::keysOf($definition, 'items')),
            $context,
        );
    }

    public function validateAnswer(array $definition, mixed $answer, DecodeContext $context): AnswerValidity
    {
        return $this->validityOf($definition, $answer, $context);
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function policyParamsFor(array $definition, ScoringPolicyInterface $policy): array
    {
        $declared = $definition['policy']['params'] ?? null;

        // A definition that names no policy at all is a legacy row, and its
        // pin carries the correction off. Reading the policy's own defaults
        // there would silently apply the chance correction to a question
        // authored years before it existed.
        if (!\is_array($declared) && !\is_array($definition['policy'] ?? null)) {
            return self::legacyPolicyParams() + $policy->defaultParams();
        }

        $params = \is_array($declared) ? $declared : [];

        /** @var array<string, mixed> $params */
        return $params + $policy->defaultParams();
    }

    public function interactionPrimitive(): string
    {
        return InteractionPrimitiveRegistry::ORDER;
    }

    public function surface(): Surface
    {
        return Surface::Flow;
    }

    public function shufflesByDefault(array $definition, PresentationContext $context): bool
    {
        // Shuffling is on unless the author turns it off: an item authored
        // with its steps already in key order otherwise pays full marks to a
        // learner who submits without touching it.
        return ($definition['shuffle'] ?? true) !== false;
    }

    protected function primitive(): InteractionPrimitiveInterface
    {
        return $this->primitive;
    }
}
