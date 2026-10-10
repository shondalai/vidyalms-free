<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\QuestionType;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerValidity;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseField;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Surface;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Provider\Interaction\TextPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Scoring\PerTargetPolicy;
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
use Shondalai\VidyaLMS\Core\Registry\Scoring\TargetKey;

/**
 * Several blanks in a passage: the text primitive on the inline surface,
 * record cardinality, one field per blank.
 *
 * Definition shape:
 * {
 *   "prompt": string containing a [[key]] marker per blank,
 *   "blanks": [{"key": string, "accepted": [string, ...]}, ...],
 *   "case_sensitive": optional bool, default false,
 *   "policy": optional {"key": string, "params": object},
 *   "feedback": optional string shown after grading
 * }
 *
 * per_target is the marking rule, and it is the shared implementation rather
 * than a per-type formula, which is the whole reason four per-type
 * restatements of the same rule had drifted into three different
 * denominators. Each blank expects any one of its accepted spellings, which
 * is the "any" match mode; a gap that expects a set of values uses the "set"
 * mode, and a superset of that set scores zero for that target.
 */
final class FillBlankQuestionType implements
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
        return 'fill_blank';
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.question_type.fill_blank');
    }

    public function group(): string
    {
        return ReportingMetadataInterface::GROUP_TEXT_ENTRY;
    }

    public function order(): int
    {
        return 20;
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
        return [PerTargetPolicy::KEY];
    }

    public function defaultPolicy(): string
    {
        return PerTargetPolicy::KEY;
    }

    public function legacyPolicy(): string
    {
        return PerTargetPolicy::KEY;
    }

    public function responseShape(): ResponseShape
    {
        // A record of single strings, one field per blank. The signature is
        // what tells this apart from an extended response's {text, files},
        // which is why the shape carries one at all.
        return ResponseShape::record([
            new ResponseField('blank', Cardinality::Single, BaseType::StringValue),
        ]);
    }

    public function definitionSchema(): array
    {
        return DefinitionRules::schema(
            self::class,
            [
                'prompt' => ['type' => 'string', 'minLength' => 1],
                'blanks' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['key', 'accepted'],
                        'properties' => [
                            'key' => ['type' => 'string', 'minLength' => 1],
                            'accepted' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
                'case_sensitive' => ['type' => 'boolean', 'default' => false],
                'policy' => DefinitionRules::policyRefSchema(),
                'feedback' => ['type' => 'string'],
                'scoring_intent' => ['enum' => ['machine', 'human']],
                'layout_classes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            ['prompt', 'blanks'],
        );
    }

    public function presentableKeys(): array
    {
        return ['prompt', 'blanks', 'layout_classes'];
    }

    public function secretKeys(): array
    {
        return ['accepted', 'feedback'];
    }

    public function exampleDefinition(): array
    {
        return [
            'prompt' => 'Fill [[one]] and [[two]].',
            'blanks' => [
                ['key' => 'one', 'accepted' => ['alpha']],
                ['key' => 'two', 'accepted' => ['beta']],
            ],
            'feedback' => 'Well done.',
        ];
    }

    public function validateDefinition(array $definition): void
    {
        DefinitionRules::requirePrompt($definition);
        $keys = DefinitionRules::requireKeyedList($definition, 'blanks', withLabel: false);
        $this->validatePolicyReference($definition);

        $blanks = $definition['blanks'] ?? [];

        if (\is_array($blanks)) {
            foreach ($blanks as $blank) {
                if (\is_array($blank)) {
                    /** @var array<string, mixed> $blank */
                    DefinitionRules::requireStringList($blank, 'accepted', allowEmpty: false);
                }
            }
        }

        $prompt = $definition['prompt'] ?? '';
        $prompt = \is_string($prompt) ? $prompt : '';

        foreach ($keys as $key) {
            if (!str_contains($prompt, '[[' . $key . ']]')) {
                throw ValidationException::forField(
                    'prompt',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    sprintf('The prompt is missing the [[%s]] marker.', $key),
                );
            }
        }

        DefinitionRules::requireOptionalBoolField($definition, 'case_sensitive');
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
        $blanks = [];

        foreach (DefinitionRules::presentKeyedList($definition, 'blanks') as $blank) {
            $blanks[] = ['key' => $blank['key']];
        }

        return [
            'prompt' => $definition['prompt'] ?? '',
            'blanks' => $blanks,
        ] + DefinitionRules::presentConstraints($definition);
    }

    public function grade(array $definition, mixed $answer): GradeResult
    {
        return $this->gradeInContext($definition, $answer, GradingContext::detached());
    }

    public function gradeInContext(array $definition, mixed $answer, GradingContext $context): GradeResult
    {
        return $this->scoreThrough($definition, $answer, ScoringKey::targets($this->targetsOf($definition)), $context);
    }

    public function validateAnswer(array $definition, mixed $answer, DecodeContext $context): AnswerValidity
    {
        return $this->validityOf($definition, $answer, $context);
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
     * @return array<string, TargetKey>
     */
    private function targetsOf(array $definition): array
    {
        $blanks = $definition['blanks'] ?? [];

        if (!\is_array($blanks)) {
            return [];
        }

        $caseSensitive = DefinitionRules::caseSensitive($definition);
        $targets = [];

        foreach ($blanks as $blank) {
            if (!\is_array($blank) || !\is_string($blank['key'] ?? null)) {
                continue;
            }

            $accepted = $blank['accepted'] ?? [];
            /** @var list<string> $accepted */
            $accepted = \is_array($accepted) ? array_values(array_filter($accepted, '\is_string')) : [];

            $targets[$blank['key']] = TargetKey::anyOf($accepted, $caseSensitive);
        }

        return $targets;
    }
}
