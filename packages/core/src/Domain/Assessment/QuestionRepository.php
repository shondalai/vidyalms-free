<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

interface QuestionRepository
{
    public function add(Question $question): Question;

    public function byId(int $id): ?Question;

    public function save(Question $question): void;

    /**
     * Several questions at once, keyed by id, in whatever order the ids
     * arrived is irrelevant: the caller already knows the order it wants.
     *
     * A composition may serve a question the quiz does not own (a pool draw
     * reaches into a bank), so the served set cannot always be resolved by
     * quiz.
     *
     * @param list<int> $ids
     *
     * @return array<int, Question>
     */
    public function byIds(array $ids): array;

    /**
     * Every question of the quiz, any state, ordered by position.
     *
     * @return list<Question>
     */
    public function forQuiz(int $quizId): array;

    /**
     * Only published questions, ordered by position.
     *
     * @return list<Question>
     */
    public function publishedForQuiz(int $quizId): array;

    /**
     * The next free position for the quiz: max(position) + 1, starting at 1.
     */
    public function nextPosition(int $quizId): int;

    /**
     * Published question counts for several quizzes at once, keyed by quiz
     * id; a quiz with no published question is absent.
     *
     * @param list<int> $quizIds
     *
     * @return array<int, int>
     */
    public function publishedCounts(array $quizIds): array;

    /**
     * @param array<int, int> $positions question id to new position
     */
    public function updatePositions(array $positions): void;
}
