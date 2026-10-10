<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

/**
 * Persistence for attempts, their answers and their grade history. The
 * contract is deliberately append-only for grading: attempt_grades rows can
 * only be added, never rewritten, so score history is complete forever.
 * The two record* methods update only the derived totals on the attempt row,
 * always recomputed from grade rows by the application layer.
 */
interface AttemptRepository
{
    public function add(Attempt $attempt): Attempt;

    public function byId(int $id): ?Attempt;

    public function inProgressFor(int $quizId, int $userId): ?Attempt;

    public function countFor(int $quizId, int $userId): int;

    /**
     * Every attempt one learner has made at one quiz, oldest first.
     *
     * The reportable grade needs the whole set, because attempts_counted
     * filters it and attempt_aggregation reduces what is left; the cooldown
     * needs the most recent one, including an abandoned one, so that
     * abandoning an attempt is not a free instant retry.
     *
     * @return list<Attempt>
     */
    public function forQuizAndUser(int $quizId, int $userId): array;

    /**
     * Every closed attempt at one quiz, oldest first. A regrade runs over
     * exactly this set: an attempt still in progress has no frozen result to
     * re-run.
     *
     * @return list<Attempt>
     */
    public function closedForQuiz(int $quizId): array;

    /**
     * Inserts or replaces the learner's answer to one question. Keyed by
     * attempt_id + question_id, so saving twice keeps one row.
     *
     * The submission is a value object rather than four scalars, because the
     * next field it grows would otherwise break both adapters again, and
     * because raw_input in particular is what precision marking has to judge
     * on and is impossible to backfill.
     */
    public function upsertAnswer(
        int $attemptId,
        int $questionId,
        AnswerSubmission $submission,
        \DateTimeImmutable $answeredAt,
    ): void;

    /**
     * Appends the try. attempt_answers is an upsert, so try two would
     * otherwise destroy the response the learner gave at try one, and there
     * would be nowhere for a try penalty to count from. Counting grade rows
     * cannot substitute, because a regrade also appends there.
     */
    public function recordAnswerEvent(
        int $attemptId,
        int $questionId,
        AnswerSubmission $submission,
        \DateTimeImmutable $occurredAt,
        ?string $partId = null,
    ): void;

    /**
     * How many tries the learner has taken on one question, from the
     * append-only event log.
     */
    /**
     * How many tries a response has had, counting only responses submitted
     * for processing and never bare autosaves, and never below one.
     *
     * Per part when a part is named, because the try penalty applies per
     * part rather than to the item as a whole.
     */
    public function tryCountFor(int $attemptId, int $questionId, ?string $partId = null): int;

    /**
     * Decoded answers keyed by question id.
     *
     * @return array<int, mixed>
     */
    public function answersFor(int $attemptId): array;

    /**
     * The full submissions keyed by question id, for the callers that need
     * the raw input or the declared certainty rather than the value alone.
     *
     * @return array<int, AnswerSubmission>
     */
    public function submissionsFor(int $attemptId): array;

    /**
     * Freezes the resolved policy, the presentation seed and the derived
     * deadline onto the attempt row, once, at StartAttempt.
     *
     * @param array<string, mixed> $policySnapshot
     */
    public function recordPolicySnapshot(
        int $attemptId,
        string $presentationSeed,
        string $behaviour,
        array $policySnapshot,
        ?\DateTimeImmutable $expiresAt,
    ): void;

    public function addGrade(AttemptGrade $grade): AttemptGrade;

    /**
     * All grade rows for the attempt in insertion order, oldest first.
     *
     * @return list<AttemptGrade>
     */
    public function gradesFor(int $attemptId): array;

    /**
     * Stamps the submission moment and the totals derived from the grade
     * rows written at submit time.
     */
    /**
     * @param float|null $percentage null while the attempt is still waiting
     *                               on a human grade. A submitted essay
     *                               nobody has marked is not a result, and
     *                               writing the auto-marked part of the paper
     *                               as the whole percentage reported a
     *                               learner as having scored 100 on a paper
     *                               half of which nobody had read
     */
    public function recordSubmission(
        int $attemptId,
        \DateTimeImmutable $submittedAt,
        string $status,
        float $score,
        float $maxScore,
        ?float $percentage,
        ?bool $passed,
        ?int $timeSpentSeconds,
    ): void;

    /**
     * Updates derived totals after grading recomputed them from grade rows.
     */
    public function recordGradingOutcome(
        int $attemptId,
        string $status,
        float $score,
        float $maxScore,
        float $percentage,
        ?bool $passed,
    ): void;
}
