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
use Shondalai\VidyaLMS\Core\Provider\Scoring\GuessNeutralPolicy;
use Shondalai\VidyaLMS\Core\Provider\Scoring\MatchCorrectPolicy;
use Shondalai\VidyaLMS\Core\Provider\Scoring\PlusMinusPolicy;
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
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;

/**
 * The select primitive on the flow surface, multiple identifier
 * cardinality.
 *
 * Definition shape:
 * {
 *   "prompt": string,
 *   "options": [{"key": string, "label": string, "fixed": optional bool}, ...],
 *   "correct": [string, ...], a non-empty subset of the option keys,
 *   "min_selections", "max_selections": optional ints, with their messages,
 *   "policy": optional {"key": string, "params": object},
 *   "feedback": optional string shown after grading
 * }
 *
 * **New items default to guess_neutral; existing items are pinned to
 * plus_minus.** The free build's original formula, min(1, max(0, C - I) /
 * |K|), pays 0.667 to a learner who ticks all four options of a three-key
 * item. Its min never binds, because C never exceeds |K|, so it is
 * plus_minus under another name and replacing one with the other would have
 * closed nothing. guess_neutral, max(0, C - I * |K| / |D|) / |K|, is the
 * only published rule in the family that makes "select everything" score
 * exactly zero for any option balance.
 *
 * A definition that names no policy is one authored before the field
 * existed, and it grades under plus_minus, which is arithmetically identical
 * to what it graded under before. No historic percentage moves.
 */
final class MultipleChoiceQuestionType implements
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
        return 'multiple_choice';
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.question_type.multiple_choice');
    }

    public function group(): string
    {
        return ReportingMetadataInterface::GROUP_SELECTION;
    }

    public function order(): int
    {
        return 20;
    }

    public function xapiInteractionType(): string
    {
        return 'choice';
    }

    public function qtiElement(): string
    {
        return 'qti-choice-interaction';
    }

    public function supportedDefinitionVersions(): array
    {
        return [1];
    }

    /**
     * map_response is deliberately absent, and that is the design rather
     * than an omission: the mapping object is a Pro decoration of this type,
     * so the free definition schema has no field to put one in and
     * gradeInContext builds a plain set key with no MappingSpec. Offering the
     * policy anyway let an author pick "Weighted per option", save without
     * complaint, and get an item that grades notscored for every learner and
     * therefore vanishes from the quiz total entirely rather than being
     * marked wrong, with no error at authoring time and no pending-grade
     * signal afterwards. Pro adds the field and the key together.
     *
     * @return list<string>
     */
    public function supportedPolicies(): array
    {
        return [
            GuessNeutralPolicy::KEY,
            PlusMinusPolicy::KEY,
            MatchCorrectPolicy::KEY,
        ];
    }

    public function defaultPolicy(): string
    {
        return GuessNeutralPolicy::KEY;
    }

    public function legacyPolicy(): string
    {
        return PlusMinusPolicy::KEY;
    }

    public function responseShape(): ResponseShape
    {
        return ResponseShape::of(Cardinality::Multiple, BaseType::Identifier);
    }

    public function definitionSchema(): array
    {
        return DefinitionRules::schema(
            self::class,
            [
                'prompt' => ['type' => 'string', 'minLength' => 1],
                'options' => DefinitionRules::keyedListSchema(withLabel: true),
                'correct' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
                'min_selections' => ['type' => 'integer', 'minimum' => 0],
                'max_selections' => ['type' => 'integer', 'minimum' => 0],
                'min_message' => ['type' => 'string'],
                'max_message' => ['type' => 'string'],
                'policy' => DefinitionRules::policyRefSchema(),
                'feedback' => ['type' => 'string'],
                'scoring_intent' => ['enum' => ['machine', 'human']],
                'layout_classes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            ['prompt', 'options', 'correct'],
        );
    }

    public function presentableKeys(): array
    {
        return ['prompt', 'options', 'min_selections', 'max_selections', 'min_message', 'max_message', 'layout_classes'];
    }

    public function secretKeys(): array
    {
        return ['correct', 'feedback'];
    }

    public function exampleDefinition(): array
    {
        return [
            'prompt' => 'Pick some.',
            'options' => [
                ['key' => 'a', 'label' => 'First'],
                ['key' => 'b', 'label' => 'Second'],
                ['key' => 'c', 'label' => 'Third'],
            ],
            'correct' => ['a', 'b'],
            'feedback' => 'Well done.',
        ];
    }

    public function validateDefinition(array $definition): void
    {
        DefinitionRules::requirePrompt($definition);
        $keys = DefinitionRules::requireKeyedList($definition, 'options', withLabel: true);
        $correct = DefinitionRules::requireStringList($definition, 'correct', allowEmpty: false);
        $this->validatePolicyReference($definition);

        if (\count(array_unique($correct)) !== \count($correct)) {
            throw ValidationException::forField('correct', 'Correct keys must be unique.');
        }

        foreach ($correct as $correctKey) {
            if (!\in_array($correctKey, $keys, true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField('correct', sprintf('"%s" is not an option key.', $correctKey));
            }
        }

        // guess_neutral's penalty weight is |K| / |D|, so an item with no
        // distractor is degenerate rather than merely harsh.
        if ($this->policyKeyFor($definition) === GuessNeutralPolicy::KEY && \count($keys) === \count($correct)) {
            throw ValidationException::forField(
                'options',
                'Guess-neutral marking needs at least one option that is not a correct answer.',
            );
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
            'options' => DefinitionRules::presentKeyedList($definition, 'options', $context->choiceSequence->for('options')),
        ];

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
        $correct = \is_array($correct) ? array_values(array_filter($correct, '\is_string')) : [];

        return $this->scoreThrough(
            $definition,
            $answer,
            ScoringKey::set($correct, DefinitionRules::keysOf($definition, 'options')),
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
