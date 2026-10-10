<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\ItemSessionControl;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;

/**
 * The quiz-level policy set: frozen at start, enforced on the way through,
 * and never read from the quiz again while an attempt is open.
 */
final class QuizPolicyTest extends AssessmentIntegrationTestCase
{
    public function testTheWholePolicySetIsFrozenOntoTheAttempt(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, policy: new QuizPolicy(
            navigationMode: QuizPolicy::NAVIGATION_LINEAR,
            submissionMode: QuizPolicy::SUBMISSION_SIMULTANEOUS,
            expiryPolicy: QuizPolicy::EXPIRY_GRACE_PERIOD,
            graceSeconds: 120,
            autoSubmit: false,
            attemptsCounted: QuizPolicy::COUNTED_LAST,
            attemptAggregation: 'latest',
            itemSessionControl: new ItemSessionControl(maxAttempts: 3, allowSkipping: false),
        ));
        self::assertNotNull($quiz->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        $policy = $attempt->policy();

        self::assertSame(QuizPolicy::NAVIGATION_LINEAR, $policy->navigationMode());
        self::assertSame(QuizPolicy::SUBMISSION_SIMULTANEOUS, $policy->submissionMode());
        self::assertSame(QuizPolicy::EXPIRY_GRACE_PERIOD, $policy->expiryPolicy());
        self::assertSame(120, $policy->graceSeconds());
        self::assertFalse($policy->autoSubmits());
        self::assertSame(QuizPolicy::COUNTED_LAST, $policy->attemptsCounted());
        self::assertSame('latest', $policy->attemptAggregation());
        self::assertSame(3, $policy->itemSessionControl()->maxAttempts);
        self::assertFalse($policy->itemSessionControl()->allowSkipping);
        self::assertSame('quiz_questions', $policy->compositionKey());
    }

    public function testChangingTheQuizAfterTheStartDoesNotMoveTheOpenAttempt(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, policy: new QuizPolicy(graceSeconds: 300));
        self::assertNotNull($quiz->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);

        $this->updateQuizUseCase()->execute(
            $this->teacher(),
            $quiz->id,
            'Checkpoint',
            policy: new QuizPolicy(navigationMode: QuizPolicy::NAVIGATION_LINEAR, graceSeconds: 60),
        );

        $reread = $this->attempts->byId((int) $attempt->id);
        self::assertNotNull($reread);
        self::assertSame(300, $reread->policy()->graceSeconds());
        self::assertFalse($reread->policy()->isLinear());
    }

    public function testTheDeadlineIsTheEarlierOfTheTimeLimitAndTheCloseTime(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $closesAt = $this->env->clock->now()->modify('+10 minutes');
        $quiz = $this->createQuiz(
            $courseId,
            timeLimitSeconds: 3600,
            policy: new QuizPolicy(closesAt: $closesAt),
        );
        self::assertNotNull($quiz->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);

        self::assertNotNull($attempt->expiresAt);
        self::assertSame(
            $closesAt->format('Y-m-d H:i:s'),
            $attempt->expiresAt->format('Y-m-d H:i:s'),
            'The close time bounds the attempt, so it wins over the longer limit.',
        );
    }

    public function testAQuizThatHasNotOpenedRefusesWithTheMomentItOpens(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $opensAt = $this->env->clock->now()->modify('+1 hour');
        $quiz = $this->createQuiz($courseId, policy: new QuizPolicy(opensAt: $opensAt));
        self::assertNotNull($quiz->id);

        try {
            $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
            self::fail('An unopened quiz must refuse.');
        } catch (ConflictException $refusal) {
            self::assertSame(
                ['available_at' => $opensAt->format('Y-m-d H:i:s')],
                $refusal->extensions,
            );
        }
    }

    public function testAClosedQuizStartsNothing(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, policy: new QuizPolicy(
            closesAt: $this->env->clock->now()->modify('-1 minute'),
        ));
        self::assertNotNull($quiz->id);

        $this->expectException(ConflictException::class);
        $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
    }

    public function testACooldownRefusesTheNextAttemptAndSaysWhenItMayStart(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, policy: new QuizPolicy(cooldownAfterFirstSeconds: 900));
        self::assertNotNull($quiz->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        $this->submitAttemptUseCase()->execute($this->learner(), (int) $attempt->id);
        $submittedAt = $this->env->clock->now();
        $this->env->clock->advance('PT5M');

        try {
            $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
            self::fail('The cooldown must refuse.');
        } catch (ConflictException $refusal) {
            self::assertSame(
                ['cooldown_until' => $submittedAt->modify('+900 seconds')->format('Y-m-d H:i:s')],
                $refusal->extensions,
            );
        }
    }

    public function testAnAbandonedAttemptIsNotAFreeInstantRetry(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz(
            $courseId,
            timeLimitSeconds: 600,
            policy: new QuizPolicy(cooldownAfterFirstSeconds: 300),
        );
        self::assertNotNull($quiz->id);

        $startedAt = $this->env->clock->now();
        $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);

        // The learner walks away. The attempt is never submitted, so the
        // cooldown counts from its own deadline rather than from nothing.
        $this->env->database->update(
            'attempts',
            ['status' => Attempt::STATUS_ABANDONED],
            ['quiz_id' => $quiz->id],
        );
        $this->env->clock->advance('PT11M');

        try {
            $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
            self::fail('An abandoned attempt still serves its cooldown.');
        } catch (ConflictException $refusal) {
            self::assertSame(
                ['cooldown_until' => $startedAt->modify('+900 seconds')->format('Y-m-d H:i:s')],
                $refusal->extensions,
            );
        }
    }

    public function testALinearAttemptRefusesAChangeOnAScreenAlreadyLeft(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, policy: new QuizPolicy(
            navigationMode: QuizPolicy::NAVIGATION_LINEAR,
        ));
        self::assertNotNull($quiz->id);

        $add = $this->addQuestionUseCase();
        $first = $add->execute($this->teacher(), $quiz->id, 'true_false', [
            'prompt' => 'Water boils at 100C at sea level.',
            'correct' => true,
        ]);
        $second = $add->execute($this->teacher(), $quiz->id, 'true_false', [
            'prompt' => 'Ice sinks in water.',
            'correct' => false,
        ]);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        $save = $this->saveAnswersUseCase();

        $save->execute($this->learner(), (int) $attempt->id, [self::qid($first) => true]);
        $save->execute($this->learner(), (int) $attempt->id, [self::qid($second) => false]);

        // The runner posts the whole map on every autosave, so an unchanged
        // answer on an earlier screen is not a navigation and is accepted.
        $save->execute($this->learner(), (int) $attempt->id, [
            self::qid($first) => true,
            self::qid($second) => false,
        ]);

        $this->expectException(ConflictException::class);
        $save->execute($this->learner(), (int) $attempt->id, [self::qid($first) => false]);
    }

    public function testGracePeriodStopsAnswersAtTheDeadlineButStillAcceptsTheSubmit(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, timeLimitSeconds: 600, policy: new QuizPolicy(
            expiryPolicy: QuizPolicy::EXPIRY_GRACE_PERIOD,
            graceSeconds: 120,
        ));
        self::assertNotNull($quiz->id);

        $question = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', [
            'prompt' => 'Tea leaves are leaves.',
            'correct' => true,
        ]);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        $this->saveAnswersUseCase()->execute($this->learner(), (int) $attempt->id, [self::qid($question) => true]);

        // One second past the deadline: the grace buys a submit and nothing
        // else, which is the whole of what grace_period means.
        $this->env->clock->advance('PT10M1S');

        try {
            $this->saveAnswersUseCase()->execute($this->learner(), (int) $attempt->id, [self::qid($question) => false]);
            self::fail('An answer past the deadline must be refused under grace_period.');
        } catch (ConflictException) {
            // Expected.
        }

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), (int) $attempt->id);

        self::assertSame(Attempt::STATUS_GRADED, $submitted->status);
        self::assertSame(100.0, $submitted->percentage, 'It grades what was stored in time.');
    }

    public function testMustSubmitDoesNotCountALateAttempt(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, timeLimitSeconds: 600, policy: new QuizPolicy(
            expiryPolicy: QuizPolicy::EXPIRY_MUST_SUBMIT,
        ));
        self::assertNotNull($quiz->id);

        $question = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'true_false', [
            'prompt' => 'Tea leaves are leaves.',
            'correct' => true,
        ]);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        self::assertNotNull($question->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$question->id => true]);
        $this->env->clock->advance('PT30M');

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), (int) $attempt->id);

        self::assertSame(Attempt::STATUS_ABANDONED, $submitted->status);
        self::assertNull($submitted->passed);
        self::assertNull($submitted->percentage);
        self::assertFalse($submitted->countsTowardsGrade());
    }

    public function testAnUnresolvableNegativeMarkingRuleMeansNoPenaltyAndSaysSo(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, policy: new QuizPolicy(
            negativeMarkingPolicy: 'formula_scoring',
        ));
        self::assertNotNull($quiz->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        $policy = $attempt->policy();

        self::assertFalse($policy->allowsNegativeMarking());
        self::assertNull($policy->negativeMarkingPolicy());
        self::assertSame(
            'formula_scoring',
            $policy->settings[AttemptPolicySet::KEY_NEGATIVE_MARKING_SUBSTITUTED_FROM] ?? null,
        );
    }
}
