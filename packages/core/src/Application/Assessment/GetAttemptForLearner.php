<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptDeadline;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptGrade;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptTotals;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeRegistry;

/**
 * The learner-facing view of one attempt.
 *
 * The question list is the frozen manifest, so the learner sees exactly what
 * was served, in the order it was served, with the option order actually
 * presented. The projection was computed once at StartAttempt and stored, so
 * an attempt containing a question whose package has since been removed
 * still renders: that is the hardest case of the rule that a paid package is
 * a dependency of nothing, and it is only satisfiable because the projection
 * is stored rather than recomputed.
 *
 * Scores, per-question awarded points and feedback appear only after
 * submission, and only when the quiz reveals results after submit. On timed
 * quizzes the attempt carries expires_at, the server deadline, so the
 * runner's timer syncs to the server instead of trusting its own clock.
 *
 * Consecutive questions sharing a stimulus form one screen, and the stimuli
 * travel with the attempt. Without both, a reading passage with six
 * questions renders as six screens, none of which can show the passage.
 */
final class GetAttemptForLearner
{
    public function __construct(
        private readonly QuizRepository $quizzes,
        private readonly AttemptRepository $attempts,
        private readonly AttemptManifest $manifest,
        private readonly AccessGuard $guard,
        private readonly DatabasePort $database,
        private readonly QuestionTypes $types = new QuestionTypes(new QuestionTypeRegistry()),
        private readonly SignedMedia $media = new SignedMedia(),
        /**
         * The behaviours, so an item whose own session has closed can reveal
         * its outcome while the attempt is still open. Optional and last so
         * an adapter built before this keeps constructing the use case;
         * without it, nothing is revealed before submit, which is the free
         * build's own behaviour.
         */
        private readonly ?AttemptPolicyRegistry $behaviours = null,
        private readonly ?\Shondalai\VidyaLMS\Core\Port\Clock\ClockPort $clock = null,
    ) {
    }

    /**
     * Whether the behaviour in force marks an item as the learner answers it.
     */
    private function revealsDuringAttempt(Attempt $attempt): bool
    {
        $behaviour = $attempt->policy()->behaviour;

        if ($this->behaviours === null || !$this->behaviours->has($behaviour)) {
            return false;
        }

        return $this->behaviours->get($behaviour)->revealsOutcomeDuringAttempt();
    }

    private function latestGradeFor(Attempt $attempt, int $questionId): ?AttemptGrade
    {
        return AttemptTotals::latestPerQuestion($this->attempts->gradesFor($attempt->id ?? 0))[$questionId] ?? null;
    }

    /**
     * @return array{attempt: array<string, mixed>, questions: list<array<string, mixed>>, stimuli: list<array<string, mixed>>}
     */
    public function execute(Principal $principal, int $attemptId): array
    {
        $this->guard->requireAuthenticated($principal);

        $attempt = $this->attempts->byId($attemptId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Attempt', $attemptId);

        if (!$attempt->isOwnedBy($principal->userId)) {
            throw new NotAuthorizedException('Only the owner of an attempt may view it.');
        }

        $quiz = $this->quizzes->byId($attempt->quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $attempt->quizId);

        RequireAttemptAccess::check($this->database, $this->clock, $attempt, $principal->userId, $quiz->courseId);

        $revealResults = $attempt->isSubmitted() && $quiz->revealsResultsAfterSubmit();
        $answers = $this->attempts->answersFor($attemptId);
        $served = $this->manifest->forAttempt($attempt);

        return [
            'attempt' => $this->presentAttempt($attempt, $quiz, $revealResults),
            'questions' => $this->presentQuestions($attempt, $served, $answers, $revealResults),
            'stimuli' => $this->presentStimuli($served),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAttempt(Attempt $attempt, Quiz $quiz, bool $revealResults): array
    {
        return [
            'id' => $attempt->id,
            'quiz_id' => $attempt->quizId,
            'attempt_number' => $attempt->attemptNumber,
            'status' => $attempt->status,
            'started_at' => $attempt->startedAt->format('Y-m-d H:i:s'),
            'submitted_at' => $attempt->submittedAt?->format('Y-m-d H:i:s'),
            'expires_at' => AttemptDeadline::of($attempt, $quiz->timeLimitSeconds, $quiz->policy)
                ?->format('Y-m-d H:i:s'),
            // The frozen policy the runner has to obey: the behaviour in
            // force and what it was substituted for, the navigation and
            // submission modes, the expiry policy and its grace, the
            // resolved item session control, and whether integrity
            // automation is suppressed for this learner. Read from the
            // attempt and never from the quiz.
            'runtime' => AttemptRuntime::of($attempt->policy()),
            'score' => $revealResults ? $attempt->score : null,
            'max_score' => $revealResults ? $attempt->maxScore : null,
            'percentage' => $revealResults ? $attempt->percentage : null,
            'passed' => $revealResults ? $attempt->passed : null,
        ];
    }

    /**
     * @param list<ServedQuestion> $served
     * @param array<int, mixed>    $answers
     *
     * @return list<array<string, mixed>>
     */
    private function presentQuestions(Attempt $attempt, array $served, array $answers, bool $revealResults): array
    {
        $latestGrades = $revealResults
            ? AttemptTotals::latestPerQuestion($this->attempts->gradesFor($attempt->id ?? 0))
            : [];

        $presented = [];

        foreach ($served as $question) {
            $shape = [
                'id' => $question->questionId,
                'type' => $question->typeKey,
                'position' => $question->position,
                'screen' => $question->screen,
                'points' => $question->points,
                'scored' => $question->scored,
                // The shape taxonomy the client's fallback renderer keys on.
                // Without it on the wire, a registered renderer that cannot
                // read the served schema_version fell through to a
                // placeholder with no shape and guessed from the raw answer,
                // which is the difference between the placeholder promise
                // being technically kept and being genuinely graceful.
                'response_shape' => $question->responseShape,
                'stimulus_id' => $question->stimulusId,
                'choice_sequence' => $question->choiceSequence->toArray(),
                'answered_status' => $question->answeredStatus->value,
                // Storage refs become signed expiring URLs here, at read time,
                // never on the frozen projection: a frozen signature would be
                // dead the moment it expired, and review, appeals and manual
                // grading all read that column long afterwards.
                'definition' => $this->media->inProjection(
                    $this->types->forServed($question),
                    $question->presented,
                ),
                'answer' => $answers[$question->questionId] ?? null,
                // **The per-item session state the runner gates on.** Both
                // are on the contract and the runner reads tries_used to
                // lock a multi-try panel; neither was ever emitted, so the
                // panels saw zero tries forever, never locked and never
                // showed feedback, while the try penalty was charged
                // regardless. Per served question because the control is a
                // per-item rule and the count is a per-item fact.
                'tries_used' => $this->attempts->tryCountFor($attempt->id ?? 0, $question->questionId) - 1,
                'session_control' => $attempt->policy()->itemSessionControl()->toArray(),
            ];

            // **An item whose own session has closed reveals its outcome,
            // even while the attempt is open**, when the behaviour in force
            // declares that it does. That is the design's item session
            // boundary rule, and without it immediate_feedback and
            // interactive_multi_try showed a learner nothing while
            // SubmitAttempt still charged them for every try. A behaviour
            // that reveals nothing during the attempt is unaffected, which
            // is every free build.
            $reveal = $revealResults
                || ($shape['tries_used'] > 0 && $this->revealsDuringAttempt($attempt));

            if ($reveal) {
                $grade = $revealResults
                    ? ($latestGrades[$question->questionId] ?? null)
                    : $this->latestGradeFor($attempt, $question->questionId);
                $shape['points_awarded'] = $grade?->pointsAwarded;
                $shape['feedback'] = $grade?->feedback;
            }

            $presented[] = $shape;
        }

        return $presented;
    }

    /**
     * The passages the served questions point at, read once for the whole
     * attempt. A stimulus is an entity rather than a zero-point pseudo
     * question, because a pseudo question pollutes item counts, item
     * statistics and the answered display, and duplicates the passage once
     * per question.
     *
     * @param list<ServedQuestion> $served
     *
     * @return list<array<string, mixed>>
     */
    private function presentStimuli(array $served): array
    {
        $ids = [];

        foreach ($served as $question) {
            if ($question->stimulusId !== null) {
                $ids[$question->stimulusId] = true;
            }
        }

        if ($ids === []) {
            return [];
        }

        $rows = $this->database->selectAll(
            Query::from('stimuli')->whereIn('id', array_map('\intval', array_keys($ids)))->orderBy('id'),
        );

        $stimuli = [];

        foreach ($rows as $row) {
            $media = $row['media'] ?? null;
            $decoded = \is_string($media) && $media !== '' ? json_decode($media, true) : null;

            $stimuli[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'body' => $row['body'] === null ? null : (string) $row['body'],
                // The column holds media refs, and the raw document used to
                // be emitted to the learner verbatim, which breaks the
                // standing invariant that the only URL the core ever emits
                // for a private file is a signed, expiring one.
                'media' => $this->media->inStimulusMedia(\is_array($decoded) ? $decoded : null),
                'alt_text' => $row['alt_text'] === null ? null : (string) $row['alt_text'],
                'long_description' => $row['long_description'] === null ? null : (string) $row['long_description'],
            ];
        }

        return $stimuli;
    }
}
