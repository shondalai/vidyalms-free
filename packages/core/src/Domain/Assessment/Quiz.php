<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyRegistry;

/**
 * A quiz belonging to a course, optionally attached to a quiz lesson.
 * pass_mark is a whole percentage, 0 meaning "no pass mark".
 *
 * The quiz-level policy set is real columns because the attempt pipeline
 * queries them on every start and every submit, and because core must read
 * every one of them with any paid provider absent. They are resolved once
 * into the attempt's frozen policy snapshot and never read from here again
 * during an attempt, so changing one cannot move a mark under a learner who
 * is mid-quiz.
 */
final class Quiz
{
    public const SHOW_RESULTS_AFTER_SUBMIT = 'after_submit';
    public const SHOW_RESULTS_NEVER = 'never';

    public function __construct(
        public readonly ?int $id,
        public readonly int $courseId,
        public readonly ?int $lessonId,
        public readonly string $title,
        public readonly ?string $description,
        public readonly int $passMark,
        public readonly ?int $maxAttempts,
        public readonly ?int $timeLimitSeconds,
        public readonly bool $isFinal,
        public readonly string $showResults,
        public readonly QuizPolicy $policy = new QuizPolicy(),
    ) {
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->courseId,
            $this->lessonId,
            $this->title,
            $this->description,
            $this->passMark,
            $this->maxAttempts,
            $this->timeLimitSeconds,
            $this->isFinal,
            $this->showResults,
            $this->policy,
        );
    }

    public function revealsResultsAfterSubmit(): bool
    {
        return $this->showResults === self::SHOW_RESULTS_AFTER_SUBMIT;
    }

    /**
     * The attempt behaviour this quiz runs under.
     *
     * It is a column rather than a constant, and the column is what makes
     * the registry's miss contract reachable: a quiz can name a behaviour
     * the site no longer has, and StartAttempt must fall back to
     * deferred_feedback and record the substitution instead of throwing out
     * of a learner's attempt. A quiz that names nothing runs the free
     * default, which is what every quiz authored before the column did.
     */
    public function behaviourKey(): string
    {
        $key = $this->policy->behaviour;

        return $key === null || $key === '' ? AttemptPolicyRegistry::DEFERRED_FEEDBACK : $key;
    }

    /**
     * True while the quiz's availability window has not opened yet.
     */
    public function opensAfter(\DateTimeImmutable $now): bool
    {
        $opensAt = $this->policy->opensAt;

        return $opensAt !== null && $now->getTimestamp() < $opensAt->getTimestamp();
    }

    /**
     * True once the quiz has closed. A closed quiz starts no new attempt;
     * one already open runs to its frozen deadline, which the close time
     * already bounded.
     */
    public function closedAt(\DateTimeImmutable $now): bool
    {
        $closesAt = $this->policy->closesAt;

        return $closesAt !== null && $now->getTimestamp() >= $closesAt->getTimestamp();
    }
}
