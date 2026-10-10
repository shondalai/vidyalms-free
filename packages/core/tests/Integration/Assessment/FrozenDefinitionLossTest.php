<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use Shondalai\VidyaLMS\Core\Application\Assessment\ResolvedQuestionType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\ScoreStatus;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * A frozen definition the store no longer holds is a registry miss, never a
 * licence to grade against the live questions row.
 *
 * The content-addressed store exists because the live row can be rewritten
 * after an attempt is graded, so rebuilding a missing entry from it hands
 * exactly that mark movement back: the item is re-marked against whatever the
 * author has written since, silently, and the served question still reports
 * itself as frozen. It is not reachable through shipped code today, but it is
 * reachable through a partial restore, an operator prune or any future store
 * maintenance, and the failure is a changed mark with no trace.
 */
final class FrozenDefinitionLossTest extends AssessmentIntegrationTestCase
{
    private int $quizId;
    private int $firstId;
    private int $secondId;

    protected function setUp(): void
    {
        parent::setUp();

        $courseId = $this->createCourse();
        $this->createEnrolment(self::LEARNER_ID, $courseId);
        $quiz = $this->createQuiz($courseId, passMark: 50);
        self::assertNotNull($quiz->id);
        $this->quizId = $quiz->id;

        $this->firstId = $this->addChoice('Pick blue.', 'a');
        $this->secondId = $this->addChoice('Pick green.', 'a');
    }

    public function testALostFrozenDefinitionGradesNotScoredRatherThanAgainstTheEditedLiveRow(): void
    {
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);

        $this->saveAnswersUseCase()->execute($this->learner(), $attempt->id, [
            $this->firstId => 'a',
            $this->secondId => 'a',
        ]);

        // The author flips the key of the first item, then the store loses
        // the rows the attempt was frozen against.
        $this->updateQuestionUseCase()->execute($this->teacher(), $this->firstId, [
            'prompt' => 'Pick blue.',
            'options' => [['key' => 'a', 'label' => 'Blue'], ['key' => 'b', 'label' => 'Green']],
            'correct' => 'b',
        ], 1.0);
        $this->env->database->delete('question_definitions', [
            'definition_hash' => $this->frozenHashOf($attempt->id, $this->firstId),
        ]);

        $submitted = $this->submitAttemptUseCase()->execute($this->learner(), $attempt->id);

        $grades = $this->attempts->gradesFor($attempt->id);
        $byQuestion = [];

        foreach ($grades as $grade) {
            $byQuestion[$grade->questionId] = $grade;
        }

        self::assertSame(
            ScoreStatus::NotScored,
            $byQuestion[$this->firstId]->scoreStatus,
            'the item whose frozen definition is gone is not re-marked against the edited live key',
        );
        self::assertNotNull($byQuestion[$this->firstId]->feedback, 'it carries an explanatory row');
        self::assertSame(1.0, $submitted->score, 'the item that still has its frozen definition still marks');
        self::assertSame(1.0, $submitted->maxScore, 'and the lost one leaves both sums alone');
        self::assertSame(Attempt::STATUS_SUBMITTED, $submitted->status, 'a human can close the orphaned item');
    }

    public function testTheServedQuestionSaysItsFrozenDefinitionIsGone(): void
    {
        $attempt = $this->startAttemptUseCase()->execute($this->learner(), $this->quizId);
        self::assertNotNull($attempt->id);

        $this->env->database->delete('question_definitions', []);

        $reloaded = $this->attempts->byId($attempt->id);
        self::assertNotNull($reloaded);

        $served = $this->manifest->forAttempt($reloaded);

        self::assertCount(2, $served, 'the manifest still names what was served');

        foreach ($served as $question) {
            self::assertTrue($question->definitionLost);
            self::assertSame(
                ResolvedQuestionType::MISS_DEFINITION_LOST,
                $this->typeResolver->forServed($question)->miss,
            );
        }
    }

    private function frozenHashOf(int $attemptId, int $questionId): string
    {
        $row = $this->env->database->selectOne(
            Query::from('attempt_questions')
                ->select(['definition_hash'])
                ->where('attempt_id', '=', $attemptId)
                ->where('question_id', '=', $questionId),
        );

        self::assertNotNull($row);

        return (string) $row['definition_hash'];
    }

    private function addChoice(string $prompt, string $correct): int
    {
        $question = $this->addQuestionUseCase()->execute($this->teacher(), $this->quizId, 'single_choice', [
            'prompt' => $prompt,
            'options' => [['key' => 'a', 'label' => 'Blue'], ['key' => 'b', 'label' => 'Green']],
            'correct' => $correct,
        ], 1.0);

        self::assertNotNull($question->id);

        return $question->id;
    }
}
