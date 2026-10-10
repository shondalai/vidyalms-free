<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Assessment\BuildXapiStatements;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiVocabulary;
use Shondalai\VidyaLMS\Core\Domain\Events\XapiStatementBuilt;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Tests\Support\RemovableEssayType;
use Shondalai\VidyaLMS\Core\Tests\Support\RemovedEssayPlaceholder;
use Shondalai\VidyaLMS\Core\Tests\Support\TokenDeclaringType;

/**
 * The statements the free build must emit for one attempt.
 *
 * The design fixes ten interaction tokens and states that declaring them at
 * the interface level is what keeps the emitter, a SCORM export and the QTI
 * results writer generic. This proves the emitter never grows a switch on
 * type id: every token reaches a statement through the same lookup, and a
 * type whose package is gone reports "other" rather than throwing or
 * claiming a shape nothing can vouch for.
 */
final class XapiStatementsTest extends AssessmentIntegrationTestCase
{
    /** How many courses this test has seeded, so two seeds never share a slug. */
    private int $seeded = 0;

    private function builder(): BuildXapiStatements
    {
        return new BuildXapiStatements(
            $this->attempts,
            $this->quizzes,
            $this->manifest,
            $this->typeResolver,
            $this->access,
            $this->events,
            'https://learn.example.test/certificates/verify',
            $this->env->locale,
            $this->env->cache,
        );
    }

    /**
     * A read is a read.
     *
     * Publishing on every call re-dispatched every statement to every host
     * listener, so a forty-question attempt fired forty-three host events per
     * GET from a route any reports holder can call in a loop. Rewardify
     * awarding points, a webhook forwarder and an audit log all sit on that
     * bridge and none of them sees the idempotency key unless it chooses to.
     */
    public function testTheDefaultReadPublishesNothing(): void
    {
        $seeded = $this->attemptOn('true_false', true);
        $before = \count($this->env->eventBridge->published);

        $built = $this->builder()->execute($this->grader(), $seeded['attempt_id']);

        self::assertNotSame([], $built['statements']);
        self::assertSame(0, $built['published']);
        self::assertCount($before, $this->env->eventBridge->published);
    }

    /**
     * Every domain event is emitted once with a stable idempotency key, which
     * a second ask for the same attempt has to honour.
     */
    public function testPublishingTwiceEmitsTheStatementsOnce(): void
    {
        $seeded = $this->attemptOn('true_false', true);
        $builder = $this->builder();

        $first = $builder->execute($this->grader(), $seeded['attempt_id'], true);
        $after = \count($this->env->eventBridge->published);
        $second = $builder->execute($this->grader(), $seeded['attempt_id'], true);

        self::assertGreaterThan(0, $first['published']);
        self::assertSame(0, $second['published']);
        self::assertCount($after, $this->env->eventBridge->published);
        self::assertSame($first['statements'], $second['statements'], 'the documents are the same either way');
    }

    /**
     * @param array<string, mixed> $definition anything the type needs beside
     *                                         the prompt. The default is
     *                                         what true_false requires and
     *                                         what every stub type here
     *                                         ignores, so one helper serves
     *                                         both.
     *
     * @return array{quiz_id: int, question_id: int, attempt_id: int}
     */
    private function attemptOn(
        string $typeKey,
        mixed $answer,
        float $points = 4.0,
        bool $scored = true,
        int $passMark = 50,
        array $definition = ['correct' => true],
    ): array {
        // Slugs take hyphens, and a type key is snake_case; without the
        // translation the course row is refused before the emitter is
        // reached at all.
        $courseId = $this->createCourse('course-' . str_replace('_', '-', $typeKey) . '-' . ++$this->seeded);
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: $passMark);
        self::assertNotNull($quiz->id);

        $question = $this->addQuestionUseCase()->execute(
            $this->teacher(),
            $quiz->id,
            $typeKey,
            ['prompt' => 'Prompt for ' . $typeKey, ...$definition],
            $points,
            $scored,
        );
        self::assertNotNull($question->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);

        if ($answer !== null) {
            $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$question->id => $answer]);
        }

        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        return ['quiz_id' => $quiz->id, 'question_id' => $question->id, 'attempt_id' => $attempt->id];
    }

    /**
     * @param list<array<string, mixed>> $statements
     *
     * @return list<string>
     */
    private static function verbs(array $statements): array
    {
        return array_map(
            static function (array $statement): string {
                $verb = $statement['verb'];
                self::assertIsArray($verb);

                return (string) $verb['id'];
            },
            $statements,
        );
    }

    /**
     * @param list<array<string, mixed>> $statements
     *
     * @return array<string, mixed>
     */
    private static function firstWith(array $statements, string $verb): array
    {
        foreach ($statements as $statement) {
            $found = $statement['verb'];
            self::assertIsArray($found);

            if ($found['id'] === $verb) {
                return $statement;
            }
        }

        self::fail('No statement carries the verb ' . $verb . '.');
    }

    public function testTheAttemptSpineIsAttemptedThenOneAnsweredPerGradeRowThenCompletedAndTheVerdict(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 50);
        self::assertNotNull($quiz->id);
        $add = $this->addQuestionUseCase();

        $single = $add->execute($this->teacher(), $quiz->id, 'single_choice', [
            'prompt' => 'Pick the first letter.',
            'options' => [['key' => 'a', 'label' => 'A'], ['key' => 'b', 'label' => 'B']],
            'correct' => 'a',
        ], 2.0);
        $boolean = $add->execute($this->teacher(), $quiz->id, 'true_false', [
            'prompt' => 'Water is wet.',
            'correct' => true,
        ], 2.0);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
            self::qid($single) => 'a',
            self::qid($boolean) => true,
        ]);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $built = $this->builder()->execute($this->grader(), $attempt->id);

        self::assertSame($attempt->id, $built['attempt_id']);
        self::assertSame(
            [
                XapiVocabulary::VERB_ATTEMPTED,
                XapiVocabulary::VERB_ANSWERED,
                XapiVocabulary::VERB_ANSWERED,
                XapiVocabulary::VERB_COMPLETED,
                XapiVocabulary::VERB_PASSED,
            ],
            self::verbs($built['statements']),
        );

        $attempted = $built['statements'][0];
        self::assertSame(XapiVocabulary::quizActivity($quiz->id), $attempted['object']['id']);
        self::assertSame(XapiVocabulary::ACTIVITY_ASSESSMENT, $attempted['object']['definition']['type']);
        self::assertSame($built['registration'], $attempted['context']['registration']);
        self::assertSame(1, $attempted['context']['extensions'][XapiVocabulary::EXTENSION_ATTEMPT_NUMBER]);
        self::assertArrayNotHasKey('result', $attempted, 'starting an attempt is not a result');
    }

    public function testAFailedAttemptCarriesTheFailedVerb(): void
    {
        $built = $this->builder()->execute(
            $this->grader(),
            $this->attemptOn('true_false', false, passMark: 50)['attempt_id'],
        );

        self::assertContains(XapiVocabulary::VERB_FAILED, self::verbs($built['statements']));
        self::assertFalse(self::firstWith($built['statements'], XapiVocabulary::VERB_FAILED)['result']['success']);
    }

    /**
     * The learner, the quiz and the course are all identified without a
     * single join into a CMS table and without an email address, which is
     * what stops a forwarder publishing personal data the site never chose
     * to publish.
     */
    public function testTheActorIsTheUserIdAgainstTheSiteOriginAndNeverAnEmail(): void
    {
        $seeded = $this->attemptOn('true_false', true);
        $built = $this->builder()->execute($this->grader(), $seeded['attempt_id']);
        $actor = $built['statements'][0]['actor'];

        self::assertSame([
            'objectType' => 'Agent',
            'account' => [
                'homePage' => 'https://learn.example.test',
                'name' => (string) self::LEARNER_ID,
            ],
        ], $actor);
    }

    public function testASiteWithNoPublishedUrlIdentifiesTheActorAgainstTheVidyalmsNamespace(): void
    {
        $seeded = $this->attemptOn('true_false', true);

        $built = (new BuildXapiStatements(
            $this->attempts,
            $this->quizzes,
            $this->manifest,
            $this->typeResolver,
            $this->access,
            $this->events,
            '',
            $this->env->locale,
        ))->execute($this->grader(), $seeded['attempt_id']);

        self::assertSame(
            XapiVocabulary::ANONYMOUS_HOME_PAGE,
            $built['statements'][0]['actor']['account']['homePage'],
        );
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function tokenProvider(): array
    {
        $cases = [];

        foreach (XapiVocabulary::interactionTokens() as $index => $token) {
            $cases[$token] = ['x_token_' . $index, $token];
        }

        return array_values($cases);
    }

    /**
     * @dataProvider tokenProvider
     */
    public function testEveryDeclaredInteractionTokenReachesTheStatement(string $key, string $token): void
    {
        $this->questionTypes->register(new TokenDeclaringType($key, $token));

        $seeded = $this->attemptOn($key, 'anything');
        $built = $this->builder()->execute($this->grader(), $seeded['attempt_id']);
        $answered = self::firstWith($built['statements'], XapiVocabulary::VERB_ANSWERED);

        self::assertSame(XapiVocabulary::ACTIVITY_INTERACTION, $answered['object']['definition']['type']);
        self::assertSame($token, $answered['object']['definition']['interactionType']);
        self::assertSame(
            XapiVocabulary::questionActivity($seeded['question_id']),
            $answered['object']['id'],
        );
    }

    /**
     * The free six between them declare four of the ten tokens, and this is
     * the map the design's table states for them.
     */
    public function testTheFreeSixReportTheTokensTheDesignMapsThemTo(): void
    {
        $expected = [
            'single_choice' => XapiVocabulary::TOKEN_CHOICE,
            'multiple_choice' => XapiVocabulary::TOKEN_CHOICE,
            'true_false' => XapiVocabulary::TOKEN_TRUE_FALSE,
            'short_answer' => XapiVocabulary::TOKEN_FILL_IN,
            'fill_blank' => XapiVocabulary::TOKEN_FILL_IN,
            'ordering' => XapiVocabulary::TOKEN_SEQUENCING,
        ];

        foreach ($expected as $key => $token) {
            self::assertSame($token, $this->typeResolver->resolve($key)->xapiInteractionType());
        }
    }

    /**
     * A scale reports as numeric when it is marked and as likert when it is
     * an opinion item, and "scored" is a questions column rather than a
     * definition field, so only the placement can answer it.
     */
    public function testAScoredAwareTypeReportsADifferentTokenForAnUnscoredPlacement(): void
    {
        $this->questionTypes->register(new TokenDeclaringType(
            'x_scale',
            XapiVocabulary::TOKEN_NUMERIC,
            XapiVocabulary::TOKEN_LIKERT,
        ));

        $scored = $this->attemptOn('x_scale', '3', scored: true, passMark: 0);
        $opinion = $this->attemptOn('x_scale', '3', scored: false, passMark: 0);

        self::assertSame(
            XapiVocabulary::TOKEN_NUMERIC,
            self::firstWith(
                $this->builder()->execute($this->grader(), $scored['attempt_id'])['statements'],
                XapiVocabulary::VERB_ANSWERED,
            )['object']['definition']['interactionType'],
        );
        self::assertSame(
            XapiVocabulary::TOKEN_LIKERT,
            self::firstWith(
                $this->builder()->execute($this->grader(), $opinion['attempt_id'])['statements'],
                XapiVocabulary::VERB_ANSWERED,
            )['object']['definition']['interactionType'],
        );
    }

    /**
     * A type that invents a token outside the closed set of ten does not put
     * it on the wire. Reporting an unrecognised string would make every
     * consumer of the statements grow a branch for it.
     */
    public function testATokenOutsideTheClosedSetIsReportedAsOther(): void
    {
        $this->questionTypes->register(new TokenDeclaringType('x_invented', 'drag-and-drop'));

        $seeded = $this->attemptOn('x_invented', 'anything');
        $built = $this->builder()->execute($this->grader(), $seeded['attempt_id']);

        self::assertSame(
            XapiVocabulary::TOKEN_OTHER,
            self::firstWith($built['statements'], XapiVocabulary::VERB_ANSWERED)
                ['object']['definition']['interactionType'],
        );
    }

    /**
     * Removing the package that owns a type must not throw out of the
     * emitter, and must not let it claim a token nothing can vouch for.
     */
    public function testAnUnresolvableTypeReportsOtherAndCarriesNoScore(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 50);
        self::assertNotNull($quiz->id);

        $this->questionTypes->register(new RemovableEssayType($this->env->translator));
        $question = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, RemovableEssayType::KEY, [
            'prompt' => 'Explain the tide.',
        ], 4.0);
        self::assertNotNull($question->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$question->id => 'The moon.']);

        $this->questionTypes->replace(new RemovedEssayPlaceholder($this->env->translator));
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $built = $this->builder()->execute($this->grader(), $attempt->id);
        $answered = self::firstWith($built['statements'], XapiVocabulary::VERB_ANSWERED);

        self::assertSame(XapiVocabulary::TOKEN_OTHER, $answered['object']['definition']['interactionType']);
        self::assertArrayNotHasKey('score', $answered['result']);
        self::assertArrayNotHasKey('success', $answered['result']);
        self::assertSame(
            'notscored',
            $answered['context']['extensions'][XapiVocabulary::EXTENSION_SCORE_STATUS],
        );
    }

    public function testAnAnsweredStatementCarriesRawMinMaxScaledSuccessAndTheResponse(): void
    {
        $seeded = $this->attemptOn('true_false', true, points: 4.0);
        $built = $this->builder()->execute($this->grader(), $seeded['attempt_id']);
        $answered = self::firstWith($built['statements'], XapiVocabulary::VERB_ANSWERED);

        self::assertSame(
            ['raw' => 4.0, 'min' => 0.0, 'max' => 4.0, 'scaled' => 1.0],
            $answered['result']['score'],
        );
        self::assertTrue($answered['result']['success']);
        self::assertTrue($answered['result']['completion']);
        self::assertSame('true', $answered['result']['response']);
        self::assertSame(
            AnsweredStatus::Answered->value,
            $answered['context']['extensions'][XapiVocabulary::EXTENSION_ANSWERED_STATUS],
        );
    }

    /**
     * An item the learner never answered is presented rather than answered,
     * and the two must stay distinguishable: reporting that cannot separate
     * a skipped item from an unseen one produces wrong item statistics the
     * moment anyone builds a bank.
     */
    public function testASkippedItemReportsPresentedAndIncompleteWithNoResponse(): void
    {
        $seeded = $this->attemptOn('true_false', null, points: 4.0);
        $built = $this->builder()->execute($this->grader(), $seeded['attempt_id']);
        $answered = self::firstWith($built['statements'], XapiVocabulary::VERB_ANSWERED);

        self::assertFalse($answered['result']['completion']);
        self::assertArrayNotHasKey('response', $answered['result']);
        self::assertSame(
            AnsweredStatus::Presented->value,
            $answered['context']['extensions'][XapiVocabulary::EXTENSION_ANSWERED_STATUS],
        );
    }

    public function testTheCompletedStatementCarriesTheAttemptScoreAndItsDuration(): void
    {
        $seeded = $this->attemptOn('true_false', true, points: 4.0);
        $built = $this->builder()->execute($this->grader(), $seeded['attempt_id']);
        $completed = self::firstWith($built['statements'], XapiVocabulary::VERB_COMPLETED);

        self::assertTrue($completed['result']['completion']);
        self::assertSame(
            ['raw' => 4.0, 'min' => 0.0, 'max' => 4.0, 'scaled' => 1.0],
            $completed['result']['score'],
        );
        self::assertMatchesRegularExpression('/^PT\d+S$/', (string) $completed['result']['duration']);
        self::assertArrayNotHasKey(
            'success',
            $completed['result'],
            'completing is not passing; the verdict is its own statement',
        );
    }

    /**
     * The design's expression of pendingExternalScoring: the item is
     * answered at submit with no success and no score, and the human's mark
     * is a second statement when the grade row lands.
     */
    public function testAManuallyGradedItemEmitsAPendingStatementThenTheHumanMark(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 50);
        self::assertNotNull($quiz->id);

        $question = $this->addQuestionUseCase()->execute($this->teacher(), $quiz->id, 'short_answer', [
            'prompt' => 'Explain the tide.',
            'accepted' => [],
        ], 4.0);
        self::assertNotNull($question->id);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        self::assertNotNull($attempt->id);
        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [$question->id => 'The moon.']);
        $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $pending = $this->builder()->execute($this->grader(), $attempt->id);
        $awaiting = self::firstWith($pending['statements'], XapiVocabulary::VERB_ANSWERED);

        self::assertArrayNotHasKey('score', $awaiting['result']);
        self::assertArrayNotHasKey('success', $awaiting['result']);
        self::assertSame(
            'pending_external_scoring',
            $awaiting['context']['extensions'][XapiVocabulary::EXTENSION_SESSION_STATUS],
        );

        $this->gradeAnswerManuallyUseCase()->execute(
            $this->grader(),
            $attempt->id,
            $question->id,
            3.0,
            'Close enough.',
        );

        $graded = $this->builder()->execute($this->grader(), $attempt->id);
        $answered = array_values(array_filter(
            $graded['statements'],
            static fn (array $statement): bool => $statement['verb']['id'] === XapiVocabulary::VERB_ANSWERED,
        ));

        self::assertCount(2, $answered, 'the pending row and the human mark are two occurrences');
        self::assertNotSame($answered[0]['id'], $answered[1]['id']);
        self::assertSame(3.0, $answered[1]['result']['score']['raw']);
        self::assertFalse($answered[1]['result']['success'], 'three of four is partial, not correct');
    }

    /**
     * An LRS deduplicates on the statement id, so a rebuild has to produce
     * the same ids or the same fact is stored twice.
     */
    public function testStatementIdsAndTheRegistrationAreStableAcrossRebuilds(): void
    {
        $seeded = $this->attemptOn('true_false', true);

        $first = $this->builder()->execute($this->grader(), $seeded['attempt_id']);
        $second = $this->builder()->execute($this->grader(), $seeded['attempt_id']);

        self::assertSame($first['registration'], $second['registration']);
        self::assertSame(
            array_column($first['statements'], 'id'),
            array_column($second['statements'], 'id'),
        );

        foreach ($first['statements'] as $statement) {
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                (string) $statement['id'],
            );
        }
    }

    public function testEveryStatementCrossesTheEventBridgeAndCarriesItsOwnDocument(): void
    {
        $seeded = $this->attemptOn('true_false', true);
        $before = \count($this->env->eventBridge->published);

        // Publishing is asked for. The read route is a read: emitting on every
        // call fanned every statement out to every host listener again, which
        // is the exactly-once invariant broken by a GET anyone with reports
        // can call in a loop.
        $built = $this->builder()->execute($this->grader(), $seeded['attempt_id'], true);

        $published = array_values(array_filter(
            \array_slice($this->env->eventBridge->published, $before),
            static fn (object $event): bool => $event instanceof XapiStatementBuilt,
        ));

        self::assertCount(\count($built['statements']), $published);
        self::assertSame('vidyalms.xapi.statement_built', $published[0]->eventName());
        self::assertSame('xapi.statement:' . $built['statements'][0]['id'], $published[0]->idempotencyKey());
        self::assertSame($built['statements'][0], $published[0]->payload()['statement']);

        // The event happened when the statement says it did, not when the
        // fan-out ran: a forwarder that stamped its own moment onto a fact
        // recorded at submit would report every attempt as taken today.
        foreach ($published as $index => $event) {
            self::assertSame(
                $built['statements'][$index]['timestamp'],
                XapiVocabulary::timestamp($event->occurredAt),
            );
        }
    }

    public function testAGuestAndAStrangerAreBothRefused(): void
    {
        $seeded = $this->attemptOn('true_false', true);

        $this->expectException(NotAuthorizedException::class);
        $this->builder()->execute(Principal::user(self::OTHER_LEARNER_ID), $seeded['attempt_id']);
    }
}
