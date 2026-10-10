<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Registry\Context\PresentationContext;

/**
 * The context-taking half of presentation, as a capability interface rather
 * than a signature change.
 *
 * QuestionTypeInterface is shipped bytes that third parties are invited to
 * implement, so adding a parameter to presentForLearner() breaks every
 * external type. Callers probe with instanceof and fall back to the
 * context-free method, which is the defined default when absent.
 */
interface ContextAwarePresenting
{
    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function presentForLearnerInContext(array $definition, PresentationContext $context): array;
}
