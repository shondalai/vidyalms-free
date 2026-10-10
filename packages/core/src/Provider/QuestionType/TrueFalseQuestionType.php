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
 * A two-way judgement: the select primitive on the flow surface with a
 * boolean base type.
 *
 * Definition shape:
 * {
 *   "prompt": string,
 *   "correct": bool,
 *   "policy": optional {"key": string, "params": object},
 *   "feedback": optional string shown after grading
 * }
 * The answer must be a boolean; 0 and 1 are accepted as booleans, which is
 * what a form post gives.
 *
 * Nothing about this type changes in the widening, which is the point of
 * listing it: the same decoding, the same policy resolution and the same
 * result shape as the other five, with a different base type.
 */
final class TrueFalseQuestionType implements
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
        return 'true_false';
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.question_type.true_false');
    }

    public function group(): string
    {
        return ReportingMetadataInterface::GROUP_SELECTION;
    }

    public function order(): int
    {
        return 30;
    }

    public function xapiInteractionType(): string
    {
        return 'true-false';
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
        return [MatchCorrectPolicy::KEY];
    }

    public function defaultPolicy(): string
    {
        return MatchCorrectPolicy::KEY;
    }

    public function legacyPolicy(): string
    {
        return MatchCorrectPolicy::KEY;
    }

    public function responseShape(): ResponseShape
    {
        return ResponseShape::of(Cardinality::Single, BaseType::Boolean);
    }

    public function definitionSchema(): array
    {
        return DefinitionRules::schema(
            self::class,
            [
                'prompt' => ['type' => 'string', 'minLength' => 1],
                'correct' => ['type' => 'boolean'],
                'policy' => DefinitionRules::policyRefSchema(),
                'feedback' => ['type' => 'string'],
                'scoring_intent' => ['enum' => ['machine', 'human']],
                'layout_classes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            ['prompt', 'correct'],
        );
    }

    public function presentableKeys(): array
    {
        return ['prompt', 'layout_classes'];
    }

    public function secretKeys(): array
    {
        return ['correct', 'feedback'];
    }

    public function exampleDefinition(): array
    {
        return [
            'prompt' => 'Decide.',
            'correct' => true,
            'feedback' => 'Well done.',
        ];
    }

    public function validateDefinition(array $definition): void
    {
        DefinitionRules::requirePrompt($definition);
        $this->validatePolicyReference($definition);

        if (!\is_bool($definition['correct'] ?? null)) {
            throw ValidationException::forField('correct', 'The correct answer must be true or false.');
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
        return ['prompt' => $definition['prompt'] ?? ''] + DefinitionRules::presentConstraints($definition);
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
            ScoringKey::value(\is_bool($correct) ? $correct : null),
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
