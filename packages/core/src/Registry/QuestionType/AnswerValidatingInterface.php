<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerValidity;
use Shondalai\VidyaLMS\Core\Registry\Context\DecodeContext;

/**
 * Save-time validation of a response against the interaction's own
 * constraints. QTI treats an invalid response as a state distinct from a
 * wrong one, and so does this.
 *
 * The validity is returned to SaveAnswers, not to grade(), so an
 * over-selected answer is recorded as invalid rather than being silently
 * graded as wrong. A type that does not implement this keeps today's
 * behaviour, which is that any JSON is accepted.
 *
 * An invalid answer is stored, not refused. Refusing the save makes the
 * "attempted" answered status unreachable by construction and it wedges the
 * whole attempt: the runner posts the entire answer map on every autosave,
 * so a refusal that rejects one key rejects every other key the learner
 * writes for the rest of the attempt.
 */
interface AnswerValidatingInterface
{
    /**
     * @param array<string, mixed> $definition
     */
    public function validateAnswer(array $definition, mixed $answer, DecodeContext $context): AnswerValidity;
}
