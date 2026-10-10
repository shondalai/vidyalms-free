<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefaultDefinitionVersion;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ManualGradingDecidingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PartScoringInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionPart;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\GradingContext;

/**
 * The smallest composite: one questions row, one points value, two
 * human-marked parts.
 *
 * It stands in for the multi-part item Pro ships, so the free build's own
 * suite proves the per-part manual grading path end to end before any
 * composite exists. Without it, the first manual row would close the whole
 * item and the second part would never be graded.
 */
final class TwoPartEssayType implements QuestionTypeInterface, PartScoringInterface, ManualGradingDecidingInterface
{
    use DefaultDefinitionVersion;

    public const KEY = 'x_acme_two_part';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Two part essay';
    }

    public function parts(array $definition): array
    {
        return [
            new QuestionPart('a', 1.0, true, 'Part one'),
            new QuestionPart('b', 3.0, true, 'Part two'),
        ];
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
}
