<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Registry\QuestionType\AccessibilityDeclaringInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefaultDefinitionVersion;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;

/**
 * A type that declares pointer dragging and nothing else, which is exactly
 * what the registry must refuse. The same class with a justification, or with
 * the other two modes, must be accepted.
 */
final class DragOnlyType implements QuestionTypeInterface, AccessibilityDeclaringInterface
{
    use DefaultDefinitionVersion;

    public const KEY = 'x_acme_drag';

    /**
     * @param list<string> $modes
     */
    public function __construct(
        private readonly array $modes = [AccessibilityDeclaringInterface::POINTER_DRAG],
        private readonly ?string $justification = null,
        private readonly ?string $conformance = null,
        private readonly string $key = self::KEY,
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Drag';
    }

    public function interactionModes(): array
    {
        return $this->modes;
    }

    public function essentialDraggingJustification(): ?string
    {
        return $this->justification;
    }

    public function conformanceResult(): ?string
    {
        return $this->conformance;
    }

    public function validateDefinition(array $definition): void
    {
    }

    public function presentForLearner(array $definition): array
    {
        return ['prompt' => $definition['prompt'] ?? ''];
    }

    public function grade(array $definition, mixed $answer): GradeResult
    {
        return GradeResult::incorrect();
    }
}
