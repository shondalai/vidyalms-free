<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\QuestionType;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerValidity;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoringIntent;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Surface;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Provider\Interaction\TextPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Scoring\ExternalPolicy;
use Shondalai\VidyaLMS\Core\Provider\Scoring\TextMatchPolicy;
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
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ManualGradingDecidingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PrimitiveComposingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ReportingMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ScoringPolicyAwareInterface;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringKey;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Scoring\TextMatcher;

/**
 * A typed word or phrase: the text primitive on the inline surface.
 *
 * Definition shape:
 * {
 *   "prompt": string,
 *   "accepted": [string, ...], may be empty,
 *   "case_sensitive": optional bool, default false,
 *   "policy": optional {"key": string, "params": object},
 *   "feedback": optional string shown after grading
 * }
 *
 * An empty accepted list means the author declared a human marks it, and
 * that declaration is what the grading queue reads. It is expressed as
 * scoring intent rather than as a boolean on the result, because whether a
 * human is needed must not depend on whether the learner wrote anything: an
 * essay nobody opened still needs a human, and deciding from the stored
 * answer is what wrote it as an automatic zero and sent the attempt straight
 * to graded with the pass flag settled.
 *
 * The accepted list is compiled into an ordered matcher list at fraction
 * 1.0, exact when the question is case sensitive and exact_ci when it is
 * not. Case sensitivity is then a property of the matcher and of nothing
 * else, which is how one concept stops having four controls with no stated
 * precedence.
 */
final class ShortAnswerQuestionType implements
    QuestionTypeInterface,
    DefinitionContractInterface,
    DefinitionVersionAwareInterface,
    PrimitiveComposingInterface,
    ScoringPolicyAwareInterface,
    ReportingMetadataInterface,
    ManualGradingDecidingInterface,
    ContextAwarePresenting,
    ContextAwareGrading,
    AnswerValidatingInterface
{
    use DefaultDefinitionVersion;
    use PolicyBackedGrading;

    private readonly TextPrimitive $primitive;

    public function __construct(
        private readonly TranslatorPort $translator,
        ?ScoringPolicyRegistry $policies = null,
    ) {
        $this->primitive = new TextPrimitive();
        $this->freePolicyFallback = $policies;
    }

    public function key(): string
    {
        return 'short_answer';
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.question_type.short_answer');
    }

    public function group(): string
    {
        return ReportingMetadataInterface::GROUP_TEXT_ENTRY;
    }

    public function order(): int
    {
        return 10;
    }

    public function xapiInteractionType(): string
    {
        return 'fill-in';
    }

    public function qtiElement(): string
    {
        return 'qti-text-entry-interaction';
    }

    public function supportedDefinitionVersions(): array
    {
        return [1];
    }

    public function supportedPolicies(): array
    {
        return [TextMatchPolicy::KEY, ExternalPolicy::KEY];
    }

    public function defaultPolicy(): string
    {
        return TextMatchPolicy::KEY;
    }

    public function legacyPolicy(): string
    {
        return TextMatchPolicy::KEY;
    }

    public function responseShape(): ResponseShape
    {
        return ResponseShape::of(Cardinality::Single, BaseType::StringValue);
    }

    public function definitionSchema(): array
    {
        return DefinitionRules::schema(
            self::class,
            [
                'prompt' => ['type' => 'string', 'minLength' => 1],
                'accepted' => ['type' => 'array', 'items' => ['type' => 'string']],
                'case_sensitive' => ['type' => 'boolean', 'default' => false],
                'policy' => DefinitionRules::policyRefSchema(),
                'feedback' => ['type' => 'string'],
                'scoring_intent' => ['enum' => ['machine', 'human']],
                'layout_classes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            ['prompt'],
        );
    }

    public function presentableKeys(): array
    {
        return ['prompt', 'layout_classes'];
    }

    public function secretKeys(): array
    {
        return ['accepted', 'feedback'];
    }

    public function exampleDefinition(): array
    {
        return [
            'prompt' => 'Type it.',
            'accepted' => ['the answer'],
            'case_sensitive' => true,
            'feedback' => 'Well done.',
        ];
    }

    public function validateDefinition(array $definition): void
    {
        DefinitionRules::requirePrompt($definition);
        DefinitionRules::requireStringList($definition, 'accepted', allowEmpty: true);
        DefinitionRules::requireOptionalBoolField($definition, 'case_sensitive');
        DefinitionRules::requireOptionalStringField($definition, 'feedback');
        $this->validatePolicyReference($definition);

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
        return $this->scoreThrough(
            $definition,
            $answer,
            ScoringKey::matchers($this->matchersOf($definition)),
            $context,
        );
    }

    public function validateAnswer(array $definition, mixed $answer, DecodeContext $context): AnswerValidity
    {
        return $this->validityOf($definition, $answer, $context);
    }

    /**
     * The cheap answer, and the one that does not consult the learner's
     * response at all.
     */
    public function requiresHumanGrader(array $definition, GradingContext $context): bool
    {
        if (ScoringIntent::ofDefinition($definition)->isHuman()) {
            return true;
        }

        if ($this->policyKeyFor($definition) === ExternalPolicy::KEY) {
            return true;
        }

        return $this->matchersOf($definition) === [];
    }

    public function interactionPrimitive(): string
    {
        return InteractionPrimitiveRegistry::TEXT;
    }

    public function surface(): Surface
    {
        return Surface::Inline;
    }

    public function shufflesByDefault(array $definition, PresentationContext $context): bool
    {
        return ($definition['shuffle'] ?? false) === true;
    }

    protected function primitive(): InteractionPrimitiveInterface
    {
        return $this->primitive;
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return list<TextMatcher>
     */
    private function matchersOf(array $definition): array
    {
        $accepted = $definition['accepted'] ?? [];

        if (!\is_array($accepted)) {
            return [];
        }

        /** @var list<string> $accepted */
        $accepted = array_values(array_filter($accepted, '\is_string'));

        return TextMatcher::fromAcceptedList($accepted, DefinitionRules::caseSensitive($definition));
    }
}
