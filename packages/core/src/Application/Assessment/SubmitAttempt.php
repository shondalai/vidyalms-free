<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Notification\TransactionalOutbox;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\AttemptDisposition;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AnswerSubmission;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptDeadline;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptGrade;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptTotals;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\SessionStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Events\AttemptSubmitted;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\I18n\FixedLocale;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;
use Shondalai\VidyaLMS\Core\Registry\Context\GradingContext;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;

/**
 * Closes an attempt and grades every served question server-side through the
 * question type providers. One attempt_grades row is written per question
 * (grader_id null for automatic grades); totals are derived from those rows.
 * When at least one answer needs a human, the attempt stays "submitted" with
 * a null passed flag until manual grading completes.
 *
 * Three corrections land here, and every one of them changes what an attempt
 * reports.
 *
 * **The served set is the frozen manifest**, not whatever the quiz happens
 * to contain at submit time, so a question published mid-attempt is no
 * longer graded although the learner never saw it.
 *
 * **An unscored question contributes to neither sum.** Without it an opinion
 * scale is either unauthorable or a free mark that moves the pass
 * percentage.
 *
 * **Pending-human is decided from the authored intent, never from whether an
 * answer is present.** An essay nobody opened used to be skipped, written as
 * an automatic zero, and the attempt went straight to graded with the pass
 * flag settled. It now gets a grade row with session status
 * pending_external_scoring and score status notscored, and the attempt waits.
 *
 * On a timed quiz a submission past the deadline plus grace still succeeds,
 * but because SaveAnswers refuses late saves it can only grade the answers
 * stored in time.
 */
final class SubmitAttempt
{
    public function __construct(
        private readonly QuizRepository $quizzes,
        private readonly AttemptRepository $attempts,
        private readonly AttemptManifest $manifest,
        private readonly QuestionTypes $types,
        private readonly ScoringPolicyRegistry $policies,
        private readonly AccessGuard $guard,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
        private readonly DatabasePort $database,
        private readonly LocalePort $locale = new FixedLocale(),
        /**
         * Optional so a harness with no notification engine still builds this
         * use case; absent, the submission is written and dispatched exactly
         * as before and the listener is the only enqueue path.
         */
        private readonly ?TransactionalOutbox $outbox = null,
    ) {
    }

    public function execute(Principal $principal, int $attemptId): Attempt
    {
        $this->guard->requireAuthenticated($principal);
        return $this->database->transactional(function () use ($principal, $attemptId): Attempt {
            $this->database->update('attempts', ['id' => $attemptId], ['id' => $attemptId]);
            return $this->submit($principal, $attemptId);
        });
    }

    private function submit(Principal $principal, int $attemptId): Attempt
    {
        $this->guard->requireAuthenticated($principal);

        $attempt = $this->attempts->byId($attemptId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Attempt', $attemptId);

        if (!$attempt->isOwnedBy($principal->userId)) {
            throw new NotAuthorizedException('Only the owner of an attempt may submit it.');
        }

        if (!$attempt->acceptsSubmission()) {
            throw new ConflictException('The attempt has already been submitted.');
        }

        $quiz = $this->quizzes->byId($attempt->quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $attempt->quizId);
        RequireAttemptAccess::check($this->database, $this->clock, $attempt, $principal->userId, $quiz->courseId);

        [$now, $totals, $status, $passed, $timeSpent, $percentage, $requiresManualGrading] = $this->mark($attempt, $quiz);

        // The submission row and its outbox intent commit together: the
        // graded-attempt mail is a fact the shipped copy already promises on
        // screen, so a committed submission may not lose it.
        $this->writeOutbox(function () use (
            $attemptId,
            $now,
            $status,
            $totals,
            $percentage,
            $passed,
            $timeSpent,
            $quiz,
            $attempt,
            $requiresManualGrading,
        ): array {
            $this->attempts->recordSubmission(
                $attemptId,
                $now,
                $status,
                $totals->score,
                $totals->maxScore,
                $percentage,
                $passed,
                $timeSpent,
            );

            return [null, [$this->submittedEvent($attempt, $quiz, $now, $totals, $passed, $requiresManualGrading)]];
        });

        return $this->attempts->byId($attemptId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Attempt', $attemptId);
    }

    /**
     * Marks every served question and answers what the submission row should
     * say.
     *
     * Extracted so the closure ceremony can grade an attempt it is ending on
     * the learner's behalf through exactly this code and not through a second
     * implementation of it. A second grader would be a second set of answers
     * to the negative-marking floor, the unscored exclusion and the
     * pending-human rule, and the first divergence between them would show up
     * as a mark somebody disputes.
     *
     * @return array{0: \DateTimeImmutable, 1: AttemptTotals, 2: string, 3: bool|null, 4: int, 5: float|null, 6: bool}
     */
    private function mark(Attempt $attempt, Quiz $quiz): array
    {
        $attemptId = $attempt->id ?? throw EntityNotFoundException::for('Attempt', 0);

        // The whole submission, not the bare response. answersFor() returned
        // only the decoded answer JSON and discarded the certainty, the raw
        // input, the UI state and the presentation form the same row carries,
        // so ScoringContext::$certainty was null on every grade and the
        // flagship certainty-weighted policy could not be implemented against
        // its own interface.
        $answers = $this->attempts->submissionsFor($attemptId);
        $now = $this->clock->now();
        $policy = $attempt->policy();

        $score = 0.0;
        $maxScore = 0.0;
        $requiresManualGrading = false;

        foreach ($this->manifest->forAttempt($attempt) as $question) {
            $resolved = $this->types->forServed($question);
            $submission = $answers[$question->questionId] ?? null;
            $answered = $submission !== null;
            $tries = $this->attempts->tryCountFor($attemptId, $question->questionId);

            $result = $this->resultFor(
                $resolved,
                $question,
                $submission,
                $answered,
                $tries,
                $policy->toArray(),
            );

            // The quiz-level rules act here and nowhere else: the try
            // penalty in fraction space, then the negative marking gate. No
            // question type may see either, which is why neither can be
            // applied inside grade().
            $result = AttemptMarking::apply(
                $result,
                $policy,
                $tries,
                $this->triesByPart($attemptId, $question->questionId, $result),
            );
            $awarded = $result->awardedAgainst($question->points);
            $requiresManualGrading = $requiresManualGrading || $result->requiresManualGrading();

            $this->attempts->addGrade(new AttemptGrade(
                id: null,
                attemptId: $attemptId,
                questionId: $question->questionId,
                graderId: null,
                pointsAwarded: $awarded,
                maxPoints: $question->points,
                feedback: $result->feedback,
                gradedAt: $now,
                rawScore: $result->rawScore,
                minScore: $result->minScore,
                policyKey: $result->policyKey,
                scoringParams: $result->scoringParams,
                sessionStatus: $result->sessionStatus,
                scoreStatus: $result->scoreStatus,
                state: $result->state(),
                answeredStatus: $result->answeredStatus,
                feedbackIds: $result->feedbackIds,
            ));

            $this->addPartGrades($attemptId, $question, $result, $now);

            // An unscored item is excluded from both sums, so a survey
            // question is neither a free mark nor a lost one; a notscored
            // result (a registry miss, or a pending human grade) is excluded
            // for the same reason and by the same rule.
            if (!$question->contributesToScore() || $result->scoreStatus === ScoreStatus::NotScored) {
                continue;
            }

            $score += $awarded;
            $maxScore += $question->points;
        }

        // The quiz total is floored at 0 unless the quiz allows a negative
        // one, which is the second half of the negative marking rule: the
        // item floor is a fraction, and this is the total.
        $totals = AttemptTotals::fromPoints(AttemptMarking::quizTotal($score, $policy), $maxScore);
        $status = $this->statusFor($attempt, $quiz, $policy, $requiresManualGrading, $now);
        $passed = $requiresManualGrading || $status === Attempt::STATUS_ABANDONED ? null : $totals->passed($quiz->passMark);
        $timeSpent = max(0, $now->getTimestamp() - $attempt->startedAt->getTimestamp());

        // **No percentage while a human still has to mark part of it.** A
        // NotScored result is excluded from both sums, so a paper of one
        // auto-marked question and one essay recorded 100 percent over a
        // maximum of the auto-marked half alone, and every aggregation
        // method counted it: highest, average, average of the best n and the
        // learner's own best_percentage all read a fully marked paper.
        // AttemptScore::isGraded() is "percentage is not null", so this is
        // what makes that answer the question it names, and manual grading
        // fills it in when the last pending answer closes.
        $percentage = $requiresManualGrading || $status === Attempt::STATUS_ABANDONED ? null : $totals->percentage;

        return [$now, $totals, $status, $passed, $timeSpent, $percentage, $requiresManualGrading];
    }

    /** The one construction of the submitted event, so both entry points publish the same fact. */
    private function submittedEvent(
        Attempt $attempt,
        Quiz $quiz,
        \DateTimeImmutable $now,
        AttemptTotals $totals,
        ?bool $passed,
        bool $requiresManualGrading,
    ): AttemptSubmitted {
        return new AttemptSubmitted(
            $now,
            $attempt->id ?? 0,
            $quiz->id ?? $attempt->quizId,
            $quiz->lessonId,
            $attempt->enrolmentId,
            $attempt->userId,
            $quiz->courseId,
            $totals->percentage,
            $passed,
            $requiresManualGrading,
        );
    }

    /**
     * Closes an attempt on behalf of a caller that has already authorised the
     * closure, and answers the events it produced without dispatching them.
     *
     * **The callers have already authorised.** The one caller is the seat
     * revocation ceremony, which holds an OrgScope, has taken the
     * organisation's compare-and-set lock and is inside the transaction that
     * is revoking the entitlement. It cannot come through execute(): the
     * learner is not the principal, and the organisation check execute() runs
     * would refuse precisely because the entitlement it is checking is the one
     * being revoked. That is why this entry point exists and why it is
     * written in the idiom GrantEntitlement::executeForOrganisation already
     * uses, with a sealed context object rather than scalars.
     *
     * **The events are returned rather than dispatched.** The caller is
     * mid-transaction and stages them, so a rolled back revocation does not
     * emit a submission that never happened, and the certificate listener
     * runs after the commit, sees the revoked entitlement and declines to
     * issue, which is the guard this wave leaves standing.
     *
     * @return list<\Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent>
     */
    public function executeForAccessClosure(AttemptClosureContext $context): array
    {
        $attempt = $this->attempts->byId($context->attemptId);

        if ($attempt === null || !$attempt->acceptsSubmission()) {
            return [];
        }

        if ($context->disposition === AttemptDisposition::VOID) {
            // Nothing is graded and no result is published: an abandoned
            // attempt reaches neither the reportable grade nor a regrade,
            // which is what void means and why it is not a submission with a
            // zero on it.
            $this->attempts->recordSubmission(
                $context->attemptId,
                $this->clock->now(),
                Attempt::STATUS_ABANDONED,
                0.0,
                0.0,
                null,
                null,
                max(0, $this->clock->now()->getTimestamp() - $attempt->startedAt->getTimestamp()),
            );

            return [];
        }

        $quiz = $this->quizzes->byId($attempt->quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $attempt->quizId);

        [$now, $totals, $status, $passed, $timeSpent, $percentage, $requiresManualGrading] = $this->mark($attempt, $quiz);

        $this->attempts->recordSubmission(
            $context->attemptId,
            $now,
            $status,
            $totals->score,
            $totals->maxScore,
            $percentage,
            $passed,
            $timeSpent,
        );

        return [$this->submittedEvent($attempt, $quiz, $now, $totals, $passed, $requiresManualGrading)];
    }

    /**
     * @param callable(): array{0: null, 1: list<\Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent>} $operation
     */
    private function writeOutbox(callable $operation): void
    {
        if ($this->outbox !== null) {
            $this->outbox->commit($operation);

            return;
        }

        [, $events] = $operation();
        $this->events->dispatchAll($events);
    }

    /**
     * The status a submitted attempt lands in.
     *
     * A submission that arrives past the deadline plus the grace still
     * succeeds and still grades what was stored in time, because refusing it
     * would leave the attempt open forever. What must_submit changes is
     * whether it counts: a late attempt under that policy is abandoned, so
     * it reaches neither the reportable grade nor a regrade, which is the
     * whole of what "a late attempt is not counted" means.
     */
    private function statusFor(
        Attempt $attempt,
        Quiz $quiz,
        AttemptPolicySet $policy,
        bool $requiresManualGrading,
        \DateTimeImmutable $now,
    ): string {
        // The frozen deadline and the frozen grace, both from the attempt,
        // so narrowing either on the quiz cannot retroactively abandon an
        // attempt that was already in flight under the older setting.
        $deadline = AttemptDeadline::of($attempt, $quiz->timeLimitSeconds, $quiz->policy);
        $late = $deadline !== null
            && $now->getTimestamp() > $deadline->getTimestamp() + $policy->graceSeconds();

        if ($late && $policy->expiryPolicy() === QuizPolicy::EXPIRY_MUST_SUBMIT) {
            return Attempt::STATUS_ABANDONED;
        }

        return $requiresManualGrading ? Attempt::STATUS_SUBMITTED : Attempt::STATUS_GRADED;
    }

    /**
     * @param array<string, mixed> $policySnapshot
     */
    /**
     * The tries taken on each part, so the penalty applies per part rather
     * than to the item as a whole. Applying it to the whole item is the bug
     * Moodle shipped and fixed in MDL-36955.
     *
     * @return array<string, int>
     */
    private function triesByPart(int $attemptId, int $questionId, GradeResult $result): array
    {
        $tries = [];

        foreach ($result->parts as $part) {
            $tries[$part->partId] = $this->attempts->tryCountFor($attemptId, $questionId, $part->partId);
        }

        return $tries;
    }

    /**
     * One grade row per part beside the roll-up, which carries a null part
     * id.
     *
     * The design lists attempt_grades.part_id among the free build's
     * recording obligations for item analysis, and a later release cannot
     * backfill a column that was never written: every composite graded
     * before this was permanently unanalysable per part. Only an auto-graded
     * result reaches here; a human's per-criterion marks are the rubric's
     * own rows.
     */
    private function addPartGrades(
        int $attemptId,
        ServedQuestion $question,
        GradeResult $result,
        \DateTimeImmutable $now,
    ): void {
        foreach ($result->parts as $part) {
            $maximum = $part->maxScore !== null && $part->maxScore > 0.0
                ? $part->maxScore
                : $question->points / max(1, \count($result->parts));

            $this->attempts->addGrade(new AttemptGrade(
                id: null,
                attemptId: $attemptId,
                questionId: $question->questionId,
                graderId: null,
                pointsAwarded: round($part->fraction * $maximum, 4),
                maxPoints: $maximum,
                feedback: null,
                gradedAt: $now,
                partId: $part->partId,
                rawScore: $part->rawScore,
                minScore: null,
                policyKey: $result->policyKey,
                scoringParams: [],
                sessionStatus: $part->sessionStatus,
                scoreStatus: $part->scoreStatus,
                state: $result->state(),
                answeredStatus: $part->answeredStatus,
                feedbackIds: $part->feedbackIds,
            ));
        }
    }

    /**
     * @param array<string, mixed> $policySnapshot
     */
    private function resultFor(
        ResolvedQuestionType $resolved,
        ServedQuestion $question,
        ?AnswerSubmission $submission,
        bool $answered,
        int $tries,
        array $policySnapshot,
    ): GradeResult {
        // Asked before the answer is looked at, which is the whole point:
        // an item a human must mark is pending whether or not the learner
        // wrote anything.
        if ($resolved->requiresHumanGrader($question->definition, $question->externalScored)) {
            return GradeResult::manual($resolved->missFeedback())
                ->withAnsweredStatus($answered ? AnsweredStatus::Answered : AnsweredStatus::Presented)
                ->withSessionStatus(SessionStatus::PendingExternalScoring);
        }

        if (!$answered || $submission === null) {
            return GradeResult::unanswered(AnsweredStatus::Presented);
        }

        // A client rendering claim is diagnostic data, never authority to
        // remove a served question from the denominator. This also covers
        // answers stored before the wire boundary was hardened.
        if (!$submission->wasRendered()) {
            return GradeResult::unanswered(AnsweredStatus::Presented);
        }

        return $resolved->grade($question->definition, $submission->response, GradingContext::create(
            policies: $this->policies,
            presented: $question->presented,
            choiceSequence: $question->choiceSequence,
            attemptPolicy: $policySnapshot,
            // Text comparison is locale sensitive, and the certainty the
            // learner declared lives on the answer row: both reach a policy
            // through the context or not at all.
            locale: $this->locale->current(),
            try: $tries,
            certainty: $submission->certainty,
            policyOverride: $question->policyKey,
            policyOverrideParams: $question->policyParams,
        ));
    }
}
