<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerValidity;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Surface;
use Shondalai\VidyaLMS\Core\Registry\Context\DecodeContext;
use Shondalai\VidyaLMS\Core\Registry\Context\GradingContext;
use Shondalai\VidyaLMS\Core\Registry\Context\PresentationContext;
use Shondalai\VidyaLMS\Core\Registry\Interaction\InteractionPrimitiveRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\AnswerValidatingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ContextAwareGrading;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ContextAwarePresenting;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefaultDefinitionVersion;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PrimitiveComposingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ShapeDeclaringInterface;

/**
 * A third-party type that records every context it is handed.
 *
 * It exists because a context object that is always constructed with its
 * defaults is indistinguishable from no context at all: the locale, the
 * declared certainty and the accessibility profile were all hardcoded at the
 * three call sites that build them, so the flagship Pro policies stayed
 * unimplementable without a core change, which is the exact failure the
 * contexts exist to prevent. Asserting on what a provider actually receives
 * is the only assertion that catches it.
 *
 * It declares its shape through ShapeDeclaringInterface rather than the whole
 * definition contract, which is the lighter capability the design names for
 * exactly this case and the pairing that used to lose validation silently.
 */
final class ContextRecordingType implements
    QuestionTypeInterface,
    ShapeDeclaringInterface,
    AnswerValidatingInterface,
    ContextAwarePresenting,
    ContextAwareGrading,
    PrimitiveComposingInterface
{
    use DefaultDefinitionVersion;

    public const KEY = 'x_acme_recorder';

    public ?DecodeContext $lastDecode = null;
    public ?PresentationContext $lastPresentation = null;
    public ?GradingContext $lastGrading = null;
    public int $validations = 0;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Recorder';
    }

    public function responseShape(): ResponseShape
    {
        return ResponseShape::of(Cardinality::Single, BaseType::StringValue);
    }

    public function validateDefinition(array $definition): void
    {
    }

    public function validateAnswer(array $definition, mixed $answer, DecodeContext $context): AnswerValidity
    {
        ++$this->validations;
        $this->lastDecode = $context;

        return AnswerValidity::valid();
    }

    public function presentForLearner(array $definition): array
    {
        return ['prompt' => $definition['prompt'] ?? ''];
    }

    public function presentForLearnerInContext(array $definition, PresentationContext $context): array
    {
        $this->lastPresentation = $context;

        return ['prompt' => $definition['prompt'] ?? ''];
    }

    public function grade(array $definition, mixed $answer): GradeResult
    {
        return GradeResult::incorrect();
    }

    public function gradeInContext(array $definition, mixed $answer, GradingContext $context): GradeResult
    {
        $this->lastGrading = $context;

        return GradeResult::correct();
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
        return true;
    }
}
