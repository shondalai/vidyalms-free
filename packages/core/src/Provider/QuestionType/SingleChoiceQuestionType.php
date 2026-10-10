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
use Shondalai\VidyaLMS\Core\Provider\Interaction\SelectPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Scoring\MatchCorrectPolicy;
use Shondalai\VidyaLMS\Core\Provider\Scoring\NonePolicy;
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
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ScoredReportingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ScoringPolicyAwareInterface;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;

/**
 * The select primitive on the flow surface, single identifier cardinality.
 *
 * Definition shape:
 * {
 *   "prompt": string,
 *   "options": [{"key": string, "label": string, "fixed": optional bool}, ...],
 *   "correct": string, one of the option keys,
 *   "policy": optional {"key": string, "params": object},
 *   "feedback": optional string shown after grading
 * }
 *
 * "correct" is required only when the resolved policy is not "none". A scale
 * with no right answer cannot supply one without inventing a fake key that
 * then pollutes that item's facility and discrimination, which is why "none"
 * is in the supported list at all.
 *
 * Per-choice "fixed" is a free-build field, not a Pro decoration. Without it
 * every "None of the above" item breaks the moment shuffling ships, and the
 * retrofit is a definition-format migration across every authored question.
 */
final class SingleChoiceQuestionType implements
    QuestionTypeInterface,
    DefinitionContractInterface,
    DefinitionVersionAwareInterface,
    PrimitiveComposingInterface,
    ScoringPolicyAwareInterface,
    ReportingMetadataInterface,
    ScoredReportingInterface,
    ContextAwarePresenting,
    ContextAwareGrading,
    AnswerValidatingInterface
{
    use DefaultDefinitionVersion;
    use PolicyBackedGrading;

    private readonly SelectPrimitive $primitive;

    public function __construct(
        private readonly TranslatorPort $translator,
        ?ScoringPolicyRegistry $policies = null,
    ) {
        $this->primitive = new SelectPrimitive();
        $this->freePolicyFallback = $policies;
    }

    public function key(): string
    {
        return 'single_choice';
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.question_type.single_choice');
    }

    public function group(): string
    {
        return ReportingMetadataInterface::GROUP_SELECTION;
    }

    public function order(): int
    {
        return 10;
    }

    public function xapiInteractionType(): string
    {
        return 'choice';
    }

    /**
     * The design's token table has one row that is not a type: a scalar
     * unscored and the likert preset both report likert. The preset compiles
     * to this type with the scored column off, so an opinion item authored on
     * it is a single choice that reports likert rather than choice.
     *
     * It cannot be answered from the definition: "scored" is a questions
     * column and not a definition field, which is the whole reason the preset
     * sets the column. Only the frozen manifest knows, so the core asks.
     */
    public function xapiInteractionTypeWhen(bool $scored): string
    {
        return $scored ? 'choice' : 'likert';
    }

    public function qtiElement(): string
    {
        return 'qti-choice-interaction';
    }

    public function supportedDefinitionVersions(): array
    {
        return [1];
    }

    public function supportedPolicies(): array
    {
        return [MatchCorrectPolicy::KEY, NonePolicy::KEY];
    }

    public function defaultPolicy(): string
    {
        return MatchCorrectPolicy::KEY;
    }

    public function legacyPolicy(): string
    {
        // Exact match at 1 or 0 was and stays the behaviour, so the legacy
        // pin and the default are the same provider and no migration writes
        // anything for this type.
        return MatchCorrectPolicy::KEY;
    }

    public function responseShape(): ResponseShape
    {
        return ResponseShape::of(Cardinality::Single, BaseType::Identifier);
    }

    public function definitionSchema(): array
    {
        return DefinitionRules::schema(
            self::class,
            [
                'prompt' => ['type' => 'string', 'minLength' => 1],
                'options' => DefinitionRules::keyedListSchema(withLabel: true),
                'correct' => ['type' => 'string', 'minLength' => 1],
                'policy' => DefinitionRules::policyRefSchema(),
                'feedback' => ['type' => 'string'],
                'scoring_intent' => ['enum' => ['machine', 'human']],
                'layout_classes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            ['prompt', 'options'],
        );
    }

    public function presentableKeys(): array
    {
        return ['prompt', 'options', 'layout_classes'];
    }

    public function secretKeys(): array
    {
        return ['correct', 'feedback'];
    }

    public function exampleDefinition(): array
    {
        return [
            'prompt' => 'Pick one.',
            'options' => [
                ['key' => 'a', 'label' => 'First'],
                ['key' => 'b', 'label' => 'Second'],
            ],
            'correct' => 'a',
            'feedback' => 'Well done.',
        ];
    }

    public function validateDefinition(array $definition): void
    {
        DefinitionRules::requirePrompt($definition);
        $keys = DefinitionRules::requireKeyedList($definition, 'options', withLabel: true);
        $this->validatePolicyReference($definition);

        // A scale with no right answer is authorable only because "correct"
        // is conditional on the resolved policy.
        if ($this->policyKeyFor($definition) !== NonePolicy::KEY) {
            $correct = $definition['correct'] ?? null;

            if (!\is_string($correct) || !\in_array($correct, $keys, true)) {
                throw ValidationException::forField('correct', 'The correct answer must be one of the option keys.');
            }
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
        return [
            'prompt' => $definition['prompt'] ?? '',
            'options' => DefinitionRules::presentKeyedList($definition, 'options', $context->choiceSequence->for('options')),
        ];
    }

    public function grade(array $definition, mixed $answer): GradeResult
    {
        return $this->gradeInContext($definition, $answer, GradingContext::detached());
    }

    public function gradeInContext(array $definition, mixed $answer, GradingContext $context): GradeResult
    {
        $correct = $definition['correct'] ?? null;

        return $this->scoreThrough(
            $definition,
            $answer,
            ScoringKey::value(\is_string($correct) ? $correct : null),
            $context,
        );
    }

    public function validateAnswer(array $definition, mixed $answer, DecodeContext $context): AnswerValidity
    {
        return $this->validityOf($definition, $answer, $context);
    }

    public function interactionPrimitive(): string
    {
        return InteractionPrimitiveRegistry::SELECT;
    }

    public function surface(): Surface
    {
        return Surface::Flow;
    }

    public function shufflesByDefault(array $definition, PresentationContext $context): bool
    {
        return ($definition['shuffle'] ?? false) === true;
    }

    protected function primitive(): InteractionPrimitiveInterface
    {
        return $this->primitive;
    }
}
