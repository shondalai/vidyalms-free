<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Assessment\ServedQuestion;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Registry\Composition\ComposedQuestion;
use Shondalai\VidyaLMS\Core\Registry\Composition\ComposedQuestionSet;
use Shondalai\VidyaLMS\Core\Registry\Composition\QuizCompositionContext;
use Shondalai\VidyaLMS\Core\Registry\Composition\QuizCompositionInterface;

/**
 * The composition seam: StartAttempt asks a provider which questions this
 * attempt is served, and freezes whatever it gets.
 *
 * The free default answers the quiz's own published questions, which is what
 * every quiz has always been served. A paid package resolving a slot tree,
 * running a selection pass and drawing from pools registers above it, and
 * nothing in the core changes to allow that.
 */
final class QuizCompositionTest extends AssessmentIntegrationTestCase
{
    public function testTheFreeDefaultServesThePublishedQuestionsInPositionOrder(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $add = $this->addQuestionUseCase();
        $first = $add->execute($this->teacher(), $quiz->id, 'true_false', ['prompt' => 'One.', 'correct' => true]);
        $second = $add->execute($this->teacher(), $quiz->id, 'true_false', ['prompt' => 'Two.', 'correct' => false]);

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        $served = $this->manifest->forAttempt($attempt);

        self::assertSame(
            [self::qid($first), self::qid($second)],
            array_map(static fn (ServedQuestion $question): int => $question->questionId, $served),
        );
        self::assertSame('quiz_questions', $attempt->policy()->compositionKey());
    }

    public function testTheAttemptRecordsWhichCompositionProducedItsSet(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId);
        self::assertNotNull($quiz->id);

        $add = $this->addQuestionUseCase();
        $first = $add->execute($this->teacher(), $quiz->id, 'true_false', ['prompt' => 'One.', 'correct' => true]);
        $second = $add->execute($this->teacher(), $quiz->id, 'true_false', ['prompt' => 'Two.', 'correct' => false]);

        // A composition that claims this quiz and serves the second question
        // only, at a slot's own points, with the draw recorded.
        $this->compositions->register(new class (self::qid($second)) implements QuizCompositionInterface {
            public function __construct(private readonly int $questionId)
            {
            }

            public function key(): string
            {
                return 'slots';
            }

            public function label(): string
            {
                return 'Slots';
            }

            public function priority(): int
            {
                return 100;
            }

            public function claims(QuizCompositionContext $context): bool
            {
                return true;
            }

            public function compose(QuizCompositionContext $context): ComposedQuestionSet
            {
                return new ComposedQuestionSet($this->key(), [
                    new ComposedQuestion(
                        $this->questionId,
                        points: 7.0,
                        selection: ['slot_id' => 42, 'source' => 'pool'],
                    ),
                ]);
            }
        });

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        $served = $this->manifest->forAttempt($attempt);

        self::assertCount(1, $served);
        self::assertSame(self::qid($second), $served[0]->questionId);
        self::assertSame(7.0, $served[0]->points, 'The slot weight wins, as QTI puts it on the item reference.');
        self::assertSame('slots', $attempt->policy()->compositionKey());

        $rows = $this->attemptQuestions->forAttempt((int) $attempt->id);
        self::assertSame(['selection' => ['slot_id' => 42, 'source' => 'pool']], $rows[0]->variant);
        self::assertNotSame(self::qid($first), $rows[0]->questionId);
    }

    public function testShuffleQuestionsPermutesUnderTheAttemptSeedAndStaysStable(): void
    {
        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, policy: new QuizPolicy(shuffleQuestions: true));
        self::assertNotNull($quiz->id);

        $add = $this->addQuestionUseCase();
        $ids = [];

        foreach (range(1, 6) as $index) {
            $ids[] = self::qid($add->execute($this->teacher(), $quiz->id, 'true_false', [
                'prompt' => 'Question ' . $index,
                'correct' => true,
            ]));
        }

        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $quiz->id);
        $servedOrder = array_map(
            static fn (ServedQuestion $question): int => $question->questionId,
            $this->manifest->forAttempt($attempt),
        );

        self::assertNotSame($ids, $servedOrder, 'A shuffled quiz does not serve position order.');
        self::assertSame(
            $ids,
            $this->sorted($servedOrder),
            'Shuffling reorders and never drops or duplicates a question.',
        );

        // A reload must never reorder: the manifest is frozen, not recomputed.
        self::assertSame($servedOrder, array_map(
            static fn (ServedQuestion $question): int => $question->questionId,
            $this->manifest->forAttempt($attempt),
        ));
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
