<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityEngine;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityRefusal;
use Shondalai\VidyaLMS\Core\Application\Curriculum\LessonAvailability;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Application\Organisation\RequireCurrentOrganisationAccess;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptDeadline;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\AccessibilityProfile;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\Seed;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\I18n\FixedLocale;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyContext;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;
use Shondalai\VidyaLMS\Core\Registry\Composition\ComposedQuestionSet;
use Shondalai\VidyaLMS\Core\Registry\Composition\QuizCompositionContext;
use Shondalai\VidyaLMS\Core\Registry\Composition\QuizCompositionRegistry;
use Shondalai\VidyaLMS\Core\Registry\NegativeMarking\NegativeMarkingRegistry;

/**
 * Starts (or resumes) an attempt for the calling learner. Idempotent while
 * an attempt is in progress: calling again returns the open attempt
 * unchanged instead of consuming another attempt number.
 *
 * Starting is where everything is frozen, and it has to happen here or it
 * cannot happen at all.
 *
 * The quiz policy is resolved once into a snapshot on the attempt row, so
 * changing a quiz setting cannot silently regrade an attempt already in
 * flight, and so no question type ever has a parameter through which a quiz
 * policy could reach it.
 *
 * The served question set comes from a composition rather than from the
 * quiz's own question list, so a paid package can resolve a slot tree, run a
 * selection pass and draw from pools without a core edit. Whatever it
 * answers is written to attempt_questions with the presentation each learner
 * actually gets, and the manifest records which composition produced it.
 *
 * The deadline is derived from two things and then frozen: the earlier of
 * started_at plus the time limit and the quiz's closes_at. Deriving it from
 * both is what makes changing a close time change live attempts
 * consistently; freezing it is what stops a shortened limit expiring an
 * attempt already in flight.
 */
final class StartAttempt
{
    public function __construct(
        private readonly QuizRepository $quizzes,
        private readonly AttemptRepository $attempts,
        private readonly DatabasePort $database,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
        private readonly LessonAvailability $availability,
        private readonly AttemptPolicyRegistry $attemptPolicies,
        private readonly AttemptManifest $manifest,
        private readonly RandomPort $random,
        private readonly QuizCompositionRegistry $compositions,
        private readonly NegativeMarkingRegistry $negativeMarking = new NegativeMarkingRegistry(),
        private readonly ?LearnerAccessibilityProfile $profiles = null,
        private readonly LocalePort $locale = new FixedLocale(),
        /**
         * Optional so a harness that predates the restriction engine still
         * builds this use case; absent, only the structural reachability
         * question is asked, which is the hole a drip-locked quiz started
         * through, and is why the kernel always supplies one.
         */
        private readonly ?AvailabilityEngine $engine = null,
    ) {
    }

    public function execute(Principal $principal, int $quizId): Attempt
    {
        $this->guard->requireAuthenticated($principal);

        $quiz = $this->quizzes->byId($quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $quizId);

        $course = $this->database->selectOne(Query::from('courses')->where('id', '=', $quiz->courseId));

        if ($course === null || \in_array($course['state'], ['draft', 'trashed'], true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Quiz', $quizId);
        }

        // A quiz whose lesson has left the curriculum is not a quiz any more.
        // It answers 404 rather than 403 for the same reason a heartbeat on an
        // archived lesson does: the lesson is gone from the learner's course,
        // and a refusal that distinguishes "removed" from "forbidden" tells a
        // learner about authoring decisions that are none of their business.
        // An attempt already open is left alone: it is graded on what the
        // learner did in time, and the recompute has already stopped counting
        // this quiz.
        if (!$this->availability->quizIsReachable($quizId)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Quiz', $quizId);
        }

        $enrolment = $this->activeEnrolment($principal->userId, $quiz->courseId);
        $enrolmentId = (int) $enrolment['id'];
        RequireCurrentOrganisationAccess::check($this->database, $this->clock, $principal->userId, $quiz->courseId);

        $open = $this->attempts->inProgressFor($quizId, $principal->userId);

        if ($open !== null) {
            return $open;
        }

        $this->requireAvailableToLearner($principal, $enrolment, $quizId);

        $now = $this->clock->now();

        $this->requireAvailable($quiz, $now);

        $previous = $this->attempts->forQuizAndUser($quizId, $principal->userId);
        $taken = \count($previous);

        if ($quiz->maxAttempts !== null && $taken >= $quiz->maxAttempts) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ConflictException(sprintf('The attempt limit of %d has been reached.', $quiz->maxAttempts));
        }

        $this->requireCooldownElapsed($quiz, $previous, $now);

        // A uuid with its dashes removed is 32 characters, which is exactly
        // what the column holds. The seed is not a secret; it has to be
        // unguessable only so a learner cannot predict another learner's
        // option order, and it has to be stable so a reload never reorders.
        $seed = Seed::of(str_replace('-', '', $this->random->uuid()));
        $attemptNumber = $taken + 1;

        // The learner's declared needs are read once and frozen with the
        // rest of the policy, so a profile changed mid-attempt cannot
        // reshuffle a question the learner is already looking at, and so the
        // shuffle resolution has a real value to consult rather than the
        // literal false that made the profile-suppressed branch unreachable.
        $profile = $this->profiles?->of($principal->userId) ?? AccessibilityProfile::none();

        $behaviour = $this->attemptPolicies->resolve($quiz->behaviourKey());
        $policy = $behaviour->resolve(
            $this->quizSettings($quiz),
            AttemptPolicyContext::create(
                $quizId,
                $attemptNumber,
                $seed,
                $now,
                $profile->identifier(),
                $quiz->policy,
            ),
        )->withAccessibilityProfile($profile);

        if ($this->attemptPolicies->isSubstituting($quiz->behaviourKey())) {
            // The behaviour the quiz asked for is gone. Falling back is the
            // registry's miss contract; recording which behaviour stood in is
            // what stops the substitution being invisible later.
            $policy = $policy->substitutedFrom($quiz->behaviourKey());
        }

        $policy = $this->resolveNegativeMarking($policy);

        $composed = $this->compose($quiz, $principal, $attemptNumber, $seed, $now, $policy);
        $policy = $policy->withComposition($composed->compositionKey);

        // **The deadline is resolved once, including when it is null.**
        // expires_at cannot tell "this attempt has no deadline" from "this
        // row predates the column", so a quiz with neither a time limit nor
        // a close time recomputed from the live quiz on every read: an
        // author who added a time limit while such an attempt was open
        // handed that learner a deadline already in the past, and the next
        // save answered 409. The flag says the null is the frozen answer.
        $policy = $policy->withResolvedDeadline();

        $attempt = $this->attempts->add(Attempt::start(
            $quizId,
            $enrolmentId,
            $principal->userId,
            $attemptNumber,
            $now,
            $seed->value,
            $policy->behaviour,
            $policy->toArray(),
            AttemptDeadline::derive($quiz->timeLimitSeconds, $quiz->policy->closesAt, $now),
        ));

        $this->manifest->freeze($attempt, $policy, $seed, $composed);

        return $attempt;
    }

    /**
     * The composition that owns this quiz, asked for the set this attempt is
     * served. The free default answers the quiz's own published questions,
     * which is what every quiz has always been served.
     */
    private function compose(
        Quiz $quiz,
        Principal $principal,
        int $attemptNumber,
        Seed $seed,
        \DateTimeImmutable $now,
        AttemptPolicySet $policy,
    ): ComposedQuestionSet {
        // **A quiz whose stated composition is gone refuses rather than
        // serving an empty form.** This is the one registry whose miss
        // cannot be "fall back to the free default": the free composition
        // serves the quiz's own published questions, and a quiz built
        // entirely from a placement tree has none, so the fallback served
        // the learner a paper with no questions in it. That paper submitted,
        // recorded as graded, consumed an attempt, could complete the
        // lesson, and passed at the default pass mark of 0. Nothing about
        // that is "a placeholder or the free behaviour".
        $composition = $quiz->policy->composition;

        if ($composition !== null && $composition !== '' && !$this->compositions->has($composition)) {
            throw new ConflictException(sprintf(
                'This quiz is built by the "%s" composition, which no installed package provides, '
                . 'so there is nothing to serve. Reinstall it before anyone sits this quiz.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $composition,
            ));
        }

        $context = QuizCompositionContext::create(
            $quiz->id ?? 0,
            $attemptNumber,
            $principal->userId,
            $seed,
            $now,
            $policy,
            $this->locale->current(),
        );

        return $this->compositions->resolveFor($context)->compose($context);
    }

    /**
     * The negative marking rule the quiz named, resolved once and frozen
     * with the rest of the policy.
     *
     * An unresolved rule means no penalty at all and the substitution is
     * recorded, which is the safe direction of the miss: charging a learner
     * under a rule nobody can name, on a quiz whose instructions promised a
     * penalty this site can no longer describe, is the failure worth
     * refusing.
     */
    private function resolveNegativeMarking(AttemptPolicySet $policy): AttemptPolicySet
    {
        $key = $policy->negativeMarkingPolicy();

        if ($key === null) {
            return $policy;
        }

        $rule = $this->negativeMarking->resolve($key);

        return $rule === null
            ? $policy->withoutNegativeMarking($key)
            : $policy->withNegativeMarkingFloor($rule->floorFraction());
    }

    /**
     * The availability window. A quiz that has not opened refuses with the
     * moment it opens, and one that has closed refuses outright, so the
     * client renders a countdown rather than a bare "no". An attempt already
     * open is untouched by either: it runs to the deadline the close time
     * already bounded.
     */
    private function requireAvailable(Quiz $quiz, \DateTimeImmutable $now): void
    {
        if ($quiz->opensAfter($now)) {
            $opensAt = $quiz->policy->opensAt;

            throw ConflictException::with(
                'This quiz has not opened yet.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                ['available_at' => $opensAt?->format('Y-m-d H:i:s')],
            );
        }

        if ($quiz->closedAt($now)) {
            throw new ConflictException('This quiz has closed.');
        }
    }

    /**
     * The cooldown between attempts. The earliest permitted start is the
     * previous attempt's submitted_at plus the relevant delay, and for an
     * attempt never submitted it is that attempt's started_at plus the time
     * limit plus the delay, so abandoning an attempt is not a free instant
     * retry.
     *
     * @param list<Attempt> $previous oldest first
     */
    private function requireCooldownElapsed(Quiz $quiz, array $previous, \DateTimeImmutable $now): void
    {
        $last = $previous === [] ? null : $previous[\count($previous) - 1];

        if ($last === null) {
            return;
        }

        $cooldown = $quiz->policy->cooldownAfter($last->attemptNumber);

        if ($cooldown === null) {
            return;
        }

        $from = $last->submittedAt
            ?? AttemptDeadline::derive($quiz->timeLimitSeconds, $quiz->policy->closesAt, $last->startedAt)
            ?? $last->startedAt;

        $until = $from->modify(sprintf('+%d seconds', $cooldown));

        if ($now->getTimestamp() < $until->getTimestamp()) {
            throw ConflictException::with(
                'Another attempt at this quiz is not available yet.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                ['cooldown_until' => $until->format('Y-m-d H:i:s')],
            );
        }
    }

    /**
     * The flat settings a behaviour written against the array form reads.
     * The typed set travels on the context beside it, because a datetime, a
     * nested item session control and a review matrix cannot live in a flat
     * array of scalars without inventing an encoding.
     *
     * @return array<string, mixed>
     */
    private function quizSettings(Quiz $quiz): array
    {
        $policy = $quiz->policy;

        return [
            'shuffle_questions' => $policy->shuffleQuestions,
            'shuffle_options' => $policy->shuffleOptions,
            'one_per_page' => $policy->onePerPage,
            'pass_mark' => $quiz->passMark,
            'time_limit_seconds' => $quiz->timeLimitSeconds,
            'navigation_mode' => $policy->navigationMode,
            'submission_mode' => $policy->submissionMode,
            'expiry_policy' => $policy->expiryPolicy,
            'grace_seconds' => $policy->graceSeconds,
            'auto_submit' => $policy->autoSubmit,
            'attempts_counted' => $policy->attemptsCounted,
            'attempt_aggregation' => $policy->attemptAggregation,
            'negative_marking_policy' => $policy->negativeMarkingPolicy,
            'allow_quiz_negative' => $policy->allowQuizNegative,
            'try_penalty' => $policy->tryPenalty,
        ];
    }

    /**
     * Read-only cross-context lookup into the Access tables: the learner
     * needs an active enrolment in the quiz course.
     */
    /**
     * @return array<string, mixed>
     */
    private function activeEnrolment(int $userId, int $courseId): array
    {
        $enrolment = $this->database->selectOne(
            Query::from('enrolments')
                ->where('user_id', '=', $userId)
                ->where('course_id', '=', $courseId)
                ->where('status', '=', 'active'),
        );

        if ($enrolment === null) {
            throw new NotAuthorizedException('An active enrolment in the course is required to take this quiz.');
        }

        return $enrolment;
    }

    /**
     * The restriction engine's answer for the quiz, for the learner's own
     * enrolment.
     *
     * The structural reachability check above answers "is this quiz still in
     * the curriculum" and nothing else; a quiz inside a drip-locked module
     * passed it and started. An attempt already in progress is not touched,
     * because it is graded on what the learner did in time.
     *
     * @param array<string, mixed> $enrolment
     */
    private function requireAvailableToLearner(Principal $principal, array $enrolment, int $quizId): void
    {
        $scope = $this->engine?->scopeFor($principal, null, $enrolment);

        if ($scope === null) {
            return;
        }

        $refusal = AvailabilityRefusal::for(
            $scope->entryDecision(GateSubject::TYPE_QUIZ, $quizId),
            'Quiz',
            $quizId,
        );

        if ($refusal !== null) {
            throw $refusal;
        }
    }
}
