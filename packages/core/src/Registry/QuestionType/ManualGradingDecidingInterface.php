<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Registry\Context\GradingContext;

/**
 * A cheap answer to "does a human need this", without re-running grade().
 *
 * Four call sites re-run grading only to read one boolean off the result,
 * and one of them does it for every answer of every submitted attempt in a
 * course. More importantly, the decision must not depend on whether an
 * answer is present: an essay nobody opened still needs a human, and
 * deciding from the stored answer is what wrote it as an automatic zero and
 * sent the attempt straight to graded with the pass flag settled.
 *
 * The default when a provider does not implement this is to grade a null
 * answer and read the session status off the result, which is stable
 * because grade() is deterministic.
 *
 * It takes a context for the reason every provider method does: a context
 * parameter is the only signature shape that can grow later without breaking
 * an implementation somebody else ships, and a type deciding this without
 * being able to consult the frozen attempt policy or the locale would have
 * needed the parameter added afterwards, which is a breaking change the
 * moment an add-on exists.
 */
interface ManualGradingDecidingInterface
{
    /**
     * @param array<string, mixed> $definition
     */
    public function requiresHumanGrader(array $definition, GradingContext $context): bool;
}
