<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

/**
 * Registration refused because a type declares pointer dragging without a
 * non-drag pointer mode, a keyboard mode, or a justification for why dragging
 * is essential to the construct.
 *
 * It is a refusal rather than a flag because the alternative is a learner who
 * cannot answer the item at all, and because a justification string is the
 * only thing that can distinguish "we did not build the alternative" from
 * "the construct is the dragging".
 */
final class AccessibilityRefusedException extends \RuntimeException
{
}
