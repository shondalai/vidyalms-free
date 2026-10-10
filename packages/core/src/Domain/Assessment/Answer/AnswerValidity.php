<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Answer;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;

/**
 * Whether a stored response satisfies the interaction's own constraints.
 *
 * Returned to SaveAnswers rather than to grade(), because an over-selected
 * answer is invalid, not wrong, and recording it as wrong loses the
 * distinction QTI keeps and every report needs.
 */
final class AnswerValidity
{
    /** @var list<AnswerError> */
    public readonly array $errors;

    /**
     * @param list<AnswerError> $errors
     */
    private function __construct(array $errors, public readonly bool $hasResponse)
    {
        $this->errors = array_values($errors);
    }

    public static function valid(bool $hasResponse = true): self
    {
        return new self([], $hasResponse);
    }

    /**
     * @param list<AnswerError> $errors
     */
    public static function invalid(array $errors): self
    {
        if ($errors === []) {
            throw new \InvalidArgumentException('An invalid answer needs at least one error.');
        }

        return new self($errors, true);
    }

    /** No response at all: valid, but not answered. */
    public static function empty(): self
    {
        return new self([], false);
    }

    /**
     * A completion gate the learner has not passed: reported, and still
     * `presented` rather than `attempted`.
     *
     * There is exactly one of these in the catalogue and the design states
     * its status in as many words: "min_plays unmet yields answered_status
     * presented and blocks submission when validate_responses is on; it is a
     * completion gate, not a mark". Recording it as attempted said the
     * learner had responded invalidly, which is neither what happened nor
     * what unblocks the item: an unmet gate is the same state as an
     * unanswered question, and the error beside it is what tells the learner
     * what is still to do.
     *
     * @param list<AnswerError> $errors
     */
    public static function gated(array $errors): self
    {
        if ($errors === []) {
            throw new \InvalidArgumentException('An unmet gate needs at least one error saying what it is.');
        }

        return new self($errors, false);
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function exceedsCardinality(): bool
    {
        foreach ($this->errors as $error) {
            if ($error->kind === ErrorKind::TooManySelected->value) {
                return true;
            }
        }

        return false;
    }

    public function answeredStatus(): AnsweredStatus
    {
        if (!$this->hasResponse) {
            return AnsweredStatus::Presented;
        }

        return $this->isValid() ? AnsweredStatus::Answered : AnsweredStatus::Attempted;
    }

    /**
     * @return list<array{control_id: string, kind: string, message: string, part_id: string|null}>
     */
    public function toArray(): array
    {
        return array_map(static fn (AnswerError $error): array => $error->toArray(), $this->errors);
    }
}
