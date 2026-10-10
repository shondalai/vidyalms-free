<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Registry\Context\GradingContext;

/**
 * The context-taking half of grading, as a capability interface for the same
 * reason ContextAwarePresenting is one.
 *
 * A type that implements this resolves its scoring policy through the
 * registry the context carries, which is what separates the interaction from
 * the marking rule. A type that does not keeps grading inside grade(), which
 * is the documented default.
 */
interface ContextAwareGrading
{
    /**
     * @param array<string, mixed> $definition
     */
    public function gradeInContext(array $definition, mixed $answer, GradingContext $context): GradeResult;
}
