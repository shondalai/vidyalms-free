<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AnswerSubmission;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerValidity;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptDeadline;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptQuestionRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\I18n\FixedLocale;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;
use Shondalai\VidyaLMS\Core\Registry\Context\DecodeContext;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\AnswerValidatingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ShapeForDefinition;

/**
 * Saves (or re-saves) the learner's answers while the attempt is open.
 * Answers are upserts keyed by attempt and question, so autosave replays
 * never duplicate rows. On a timed quiz, saves past the attempt deadline
 * plus the fixed grace are refused, so a late submission can only ever
 * grade the answers stored in time.
 *
 * **An invalid answer is stored, not refused.** Refusing the save makes the
 * "attempted" answered status, which means responded but invalid,
 * unreachable by construction, and it wedges the whole attempt: the runner
 * posts the entire answer map on every autosave, so validating every key
 * before writing any row means the client re-posts the identical rejected
 * payload every few seconds for the rest of the attempt and nothing else the
 * learner writes is ever stored either.
 *
 * So every syntactically decodable answer is persisted, validity is recorded
 * per question on the frozen manifest, and the call returns a per-question
 * report the shell renders into its error summary. Refusal moves to submit.
 */
final class SaveAnswers
{
    public function __construct(
        private readonly QuizRepository $quizzes,
        private readonly AttemptRepository $attempts,
        private readonly AttemptQuestionRepository $attemptQuestions,
        private readonly AttemptManifest $manifest,
        private readonly QuestionTypes $types,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
        private readonly DatabasePort $database,
        private readonly LocalePort $locale = new FixedLocale(),
    ) {
    }

    /**
     * @param array<int, mixed> $answers keyed by question id; each value is
     *                                   either the bare response or the
     *                                   envelope carrying ui_state,
     *                                   raw_input, presentation_form and
     *                                   certainty
     *
     * @return array<int, AnswerValidity> the per-question validity report
     */
    public function execute(Principal $principal, int $attemptId, array $answers): array
    {
        $this->guard->requireAuthenticated($principal);
        return $this->database->transactional(function () use ($principal, $attemptId, $answers): array {
            // Serialize autosaves on the attempt row, including the try-limit
            // check and the coalescing of unprocessed answer events.
            $this->database->update('attempts', ['id' => $attemptId], ['id' => $attemptId]);
            return $this->save($principal, $attemptId, $answers);
        });
    }

    /**
     * @param array<int, mixed> $answers
     * @return array<int, AnswerValidity>
     */
    private function save(Principal $principal, int $attemptId, array $answers): array
    {
        $this->guard->requireAuthenticated($principal);

        $attempt = $this->attempts->byId($attemptId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Attempt', $attemptId);

        if (!$attempt->isOwnedBy($principal->userId)) {
            throw new NotAuthorizedException('Only the owner of an attempt may answer it.');
        }

        if (!$attempt->acceptsAnswers()) {
            throw new ConflictException('Answers can only be saved while the attempt is in progress.');
        }

        $quiz = $this->quizzes->byId($attempt->quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $attempt->quizId);
        RequireAttemptAccess::check($this->database, $this->clock, $attempt, $principal->userId, $quiz->courseId);

        // Limit the whole autosave, not just each individual answer envelope.
        $encoded = json_encode($answers);
        if ($encoded === false || strlen($encoded) > 2 * 1024 * 1024 || count($answers) > 1000) {
            throw ValidationException::forField('answers', 'The answer save is too large. Save fewer answers at a time.');
        }

        $now = $this->clock->now();
        $policy = $attempt->policy();

        // The frozen deadline and the frozen grace, so neither shortening
        // the quiz time limit nor narrowing its grace can retroactively
        // expire an attempt already in flight.
        if ($this->answerWindowClosed($attempt, $quiz, $policy, $now)) {
            throw new ConflictException('The attempt time expired; answers can no longer be saved.');
        }

        $served = [];

        foreach ($this->manifest->forAttempt($attempt) as $question) {
            $served[$question->questionId] = $question;
        }

        // A question that is not part of this attempt is still a hard error:
        // it is a client bug or a probe, not a learner mistake, and storing
        // it would put a row on an attempt that never served it.
        foreach (array_keys($answers) as $questionId) {
            if (!isset($served[$questionId])) {
                throw ValidationException::forField(
                    'answers',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    sprintf('Question %d is not part of this quiz.', $questionId),
                );
            }
        }

        $report = [];
        $stored = $this->attempts->submissionsFor($attemptId);
        $reached = $this->furthestScreenAnswered($served, $stored);

        // **Validate the whole map before writing any row.** The navigation
        // refusal used to be thrown mid-loop, after earlier keys had already
        // been upserted, so a rejected save left a partial write behind and
        // answered 409 over a request that had half happened.
        $submissions = [];

        foreach ($answers as $questionId => $wire) {
            $question = $served[$questionId];
            $submission = AnswerSubmission::fromWire($wire);
            if ($submission->processed && $this->attempts->tryCountFor($attemptId, (int) $questionId) > 1000) {
                throw ValidationException::forField('answers', 'The maximum number of checks for this question has been reached.');
            }
            $previous = $stored[$questionId] ?? null;

            $this->requireInNavigationOrder($policy, $question, $reached, $previous, $submission);

            $submissions[$questionId] = [$submission, $previous === null || !$submission->isSameAnswerAs($previous)];
        }

        foreach ($submissions as $questionId => [$submission, $changed]) {
            $question = $served[$questionId];
            $validity = $this->validityOf($question, $submission);

            $this->attempts->upsertAnswer($attemptId, $questionId, $submission, $now);

            // An event row only when the answer actually moved. The runner
            // posts the whole map on every autosave, so recording one per
            // posted key made attempt_answer_events.try the number of
            // autosaves rather than the number of tries, which is the number
            // a try penalty multiplies. It also grew the table at questions
            // times autosaves and defeated the per-item timing the same rows
            // are supposed to reconstruct.
            if ($changed) {
                $this->attempts->recordAnswerEvent($attemptId, $questionId, $submission, $now);
            }

            $this->attemptQuestions->recordAnsweredStatus($attemptId, $questionId, $validity->answeredStatus());

            $report[$questionId] = $validity;
        }

        return $report;
    }

    /**
     * Whether the window in which this attempt accepts answers has closed.
     *
     * Under grace_period the window ends at the deadline itself: the grace
     * buys a submit and nothing else, which is what "submit only, no further
     * answers" means. Under the other two policies the grace absorbs clock
     * skew, so an answer that left the browser in time is still stored.
     */
    private function answerWindowClosed(
        Attempt $attempt,
        Quiz $quiz,
        AttemptPolicySet $policy,
        \DateTimeImmutable $now,
    ): bool {
        if ($policy->expiryPolicy() === QuizPolicy::EXPIRY_GRACE_PERIOD) {
            return AttemptDeadline::isOverdue($attempt, $quiz->timeLimitSeconds, $now, $quiz->policy);
        }

        $deadline = AttemptDeadline::of($attempt, $quiz->timeLimitSeconds, $quiz->policy);

        if ($deadline === null) {
            return false;
        }

        return $now->getTimestamp() > $deadline->getTimestamp() + $policy->graceSeconds();
    }

    /**
     * The furthest screen the learner has already answered on, which is what
     * a linear attempt may not go back behind.
     *
     * @param array<int, ServedQuestion>   $served
     * @param array<int, AnswerSubmission> $stored
     */
    private function furthestScreenAnswered(array $served, array $stored): int
    {
        $reached = 0;

        foreach (array_keys($stored) as $questionId) {
            $question = $served[$questionId] ?? null;

            if ($question !== null) {
                $reached = max($reached, $question->screen);
            }
        }

        return $reached;
    }

    /**
     * Linear navigation, enforced on the server because a client-side
     * enforcement is a suggestion.
     *
     * QTI's linear mode means the candidate may not return to a screen
     * already left, so changing an answer on an earlier screen is refused.
     * Re-posting an identical answer is not a navigation and is accepted:
     * the runner posts the whole answer map on every autosave, and refusing
     * that would wedge the attempt for a client behaving exactly as
     * designed.
     */
    private function requireInNavigationOrder(
        AttemptPolicySet $policy,
        ServedQuestion $question,
        int $reached,
        ?AnswerSubmission $previous,
        AnswerSubmission $submission,
    ): void {
        if (!$policy->isLinear()) {
            return;
        }

        // **A screen beyond the one the learner has reached is refused, not
        // only a change behind it.** Enforcing only against changes meant
        // the runner's own whole-map autosave answered every screen at once:
        // a learner who answered everything in one request was never
        // stopped, while a learner editing normally was. The furthest screen
        // already answered, plus one, is where a linear attempt may write.
        if ($previous === null && $question->screen > $reached + 1) {
            throw new ConflictException(sprintf(
                'This quiz runs in linear mode, so question %d cannot be answered yet.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $question->questionId,
            ));
        }

        // Re-posting an identical answer is not a navigation and is
        // accepted: the runner posts the whole answer map on every autosave,
        // and refusing that would wedge the attempt for a client behaving
        // exactly as designed.
        if ($previous === null || $submission->isSameAnswerAs($previous) || $question->screen >= $reached) {
            return;
        }

        throw new ConflictException(sprintf(
            'This quiz runs in linear mode, so question %d can no longer be changed.',
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $question->questionId,
        ));
    }

    /**
     * The type's own constraints, asked through the capability interface. A
     * type that does not validate keeps today's behaviour, which is that any
     * JSON is accepted; a type whose provider is gone cannot be asked at all,
     * and its answer is stored rather than refused, because refusing it would
     * lose the learner's work over an installation problem.
     */
    private function validityOf(ServedQuestion $question, AnswerSubmission $submission): AnswerValidity
    {
        $resolved = $this->types->forServed($question);
        $provider = $resolved->provider;

        if (!$resolved->isAvailable() || !$provider instanceof AnswerValidatingInterface) {
            return $submission->response === null || $submission->response === [] || $submission->response === ''
                ? AnswerValidity::empty()
                : AnswerValidity::valid();
        }

        // Asked of the item rather than of the type, because for one type in
        // the catalogue the shape is authored: an association with targets
        // produces directed pairs and one without them produces undirected
        // ones, and decoding the second under the first reads every pair one
        // way round. The precedence between the three declarations lives in
        // ShapeForDefinition, so the two independent capability interfaces
        // cannot be resolved differently here than they are on the write
        // path. Looking only at DefinitionContractInterface used to drop
        // validation entirely for a type that declares its shape through
        // ShapeDeclaringInterface, which is exactly the pairing the design
        // names for a type that validates without taking on the whole
        // definition contract.
        $shape = ShapeForDefinition::of($provider, $question->definition);

        return $provider->validateAnswer(
            $question->definition,
            $submission->response,
            // The request locale, because decoding is locale sensitive: a
            // de-DE learner typing "5,5" was parsed under en-GB rules here.
            DecodeContext::create($shape, $this->locale->current()),
        );
    }
}
