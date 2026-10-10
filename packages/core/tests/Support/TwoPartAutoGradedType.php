<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Registry\QuestionType\DefaultDefinitionVersion;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradePart;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PartScoringInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionPart;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;

/**
 * The smallest machine-marked composite: one questions row, one points
 * value, two parts the grader never sees.
 *
 * It exists so the free build's own suite proves the per-part recording
 * obligation before any composite type ships. attempt_grades.part_id is a
 * free-build column under the design's own recording rule, and a paid
 * release cannot backfill a column that was never written: every composite
 * graded while only the roll-up row was written is permanently unanalysable
 * per part.
 */
final class TwoPartAutoGradedType implements QuestionTypeInterface, PartScoringInterface
{
    use DefaultDefinitionVersion;

    public const KEY = 'x_acme_two_part_auto';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Two part auto graded';
    }

    public function parts(array $definition): array
    {
        return [
            new QuestionPart('a', 4.0, true, 'Part one'),
            new QuestionPart('b', 6.0, true, 'Part two'),
        ];
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
        $parts = [
            new GradePart('a', 1.0, 4.0, 4.0),
            new GradePart('b', 0.5, 3.0, 6.0),
        ];

        return new GradeResult(0.7, null, 7.0, 0.0, 10.0, parts: $parts);
    }
}
