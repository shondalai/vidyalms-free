<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptDeadline;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptGrade;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;

/**
 * What a grading screen needs to say about one attempt: how many answers
 * still wait for a human, and the server deadline the attempt ran under.
 *
 * Two things changed here and both were bugs against stated intent.
 *
 * It used to catch UnknownProviderException and skip, so removing a package
 * emptied the pending count silently and an attempt with an ungraded essay
 * read as fully graded. An unresolvable type is now counted as pending,
 * which is exactly what the placeholder route exists for.
 *
 * And it used to skip a question the learner left blank, so an essay nobody
 * opened never reached the queue at all. Whether a human is needed is read
 * from the authored intent, never from the presence of an answer.
 *
 * Holds no authorization; callers act on an attempt they already authorized.
 */
final class ManualGradingStatus
{
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly QuizRepository $quizzes,
        private readonly AttemptManifest $manifest,
        private readonly QuestionTypes $types,
    ) {
    }

    /**
     * The deadline of a timed attempt, derived rather than stored, so a
     * grader sees the same expires_at the learner did.
     */
    public function expiresAtOf(Attempt $attempt): ?\DateTimeImmutable
    {
        $quiz = $this->quizzes->byId($attempt->quizId);

        return AttemptDeadline::of($attempt, $quiz?->timeLimitSeconds);
    }

    /**
     * The lesson the attempt's quiz completes, when it is linked to one.
     */
    public function lessonIdOf(Attempt $attempt): ?int
    {
        return $this->quizzes->byId($attempt->quizId)?->lessonId;
    }

    public function courseIdOf(Attempt $attempt): ?int
    {
        return $this->quizzes->byId($attempt->quizId)?->courseId;
    }

    public function pendingCountFor(Attempt $attempt): int
    {
        $attemptId = $attempt->id;

        if ($attemptId === null) {
            return 0;
        }

        $manuallyGraded = self::manualKeysOf($this->attempts->gradesFor($attemptId));
        $pending = 0;

        foreach ($this->manifest->forAttempt($attempt) as $question) {
            $resolved = $this->types->forServed($question);

            foreach ($resolved->pendingPartIds($question->definition, $question->externalScored) as $partId) {
                if (!isset($manuallyGraded[self::partKey($question->questionId, $partId)])) {
                    ++$pending;
                }
            }
        }

        return $pending;
    }

    /**
     * The identity of one gradable unit: a question, or a part of one.
     *
     * A composite is one questions row grading as several, so keying on the
     * question alone lets the first manual row close a multi-part item. A
     * type with no parts has one roll-up part whose id is null, which is
     * exactly what an attempt_grades row for a whole question already stores.
     */
    public static function partKey(int $questionId, ?string $partId): string
    {
        return $questionId . ':' . ($partId ?? '');
    }

    /**
     * @param list<AttemptGrade> $grades
     *
     * @return array<string, true>
     */
    public static function manualKeysOf(array $grades): array
    {
        $keys = [];

        foreach ($grades as $grade) {
            // A manual row that still awaits a human is a comment, not a mark.
            // The feedback-only write records one against an unmarked answer,
            // and counting it as a completed manual grade would close the item
            // and settle the attempt on the strength of a sentence.
            if ($grade->isManual() && !$grade->awaitsHuman() && $grade->questionId !== null) {
                $keys[self::partKey($grade->questionId, $grade->partId)] = true;
            }
        }

        return $keys;
    }
}
