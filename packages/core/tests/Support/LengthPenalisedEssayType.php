<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Registry\Context\GradingContext;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefaultDefinitionVersion;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ManualGradeAdjustingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ManualGradingDecidingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;

/**
 * A human-marked type that deducts for length, standing in for the extended
 * response Pro ships so the free build's own suite proves the seam end to
 * end.
 *
 * The rule it implements is the design's, kept deliberately trivial here:
 * the whole point of the interface is that the core never knows what a word
 * limit is. What this file proves is that the multiplier a type returns
 * reaches the grade row, that raw_score keeps what the marker typed, and
 * that a type declaring nothing is graded exactly as it was.
 */
final class LengthPenalisedEssayType implements
    QuestionTypeInterface,
    ManualGradingDecidingInterface,
    ManualGradeAdjustingInterface
{
    use DefaultDefinitionVersion;

    public const KEY = 'x_acme_penalised_essay';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Essay with a length penalty';
    }

    public function requiresHumanGrader(array $definition, GradingContext $context): bool
    {
        return true;
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
        return GradeResult::manual();
    }

    /**
     * Half marks for an answer over the authored word count, so the test can
     * tell a mark that was multiplied from one that was not.
     *
     * @param array<string, mixed> $definition
     */
    public function manualGradeMultiplier(array $definition, mixed $answer, ?string $partId = null): float
    {
        $maximum = $definition['max_words'] ?? null;
        $words = \is_string($answer) ? \count(preg_split('/\s+/', trim($answer)) ?: []) : 0;

        return \is_int($maximum) && $words > $maximum ? 0.5 : 1.0;
    }
}
