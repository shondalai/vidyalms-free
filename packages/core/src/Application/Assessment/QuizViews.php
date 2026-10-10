<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\LessonRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\ModuleRepository;

/**
 * Builds the quiz shapes the authoring surface uses, resolving the lesson and
 * module a quiz is attached to. Read-only across the Curriculum boundary, and
 * shared so the Quizzes tab, the quiz editor and the lesson editor never
 * describe the same quiz two different ways.
 *
 * Holds no authorization; every caller is a use case that already applied the
 * per-course rule.
 */
final class QuizViews
{
    public function __construct(
        private readonly QuestionRepository $questions,
        private readonly LessonRepository $lessons,
        private readonly ModuleRepository $modules,
    ) {
    }

    /**
     * Every quiz of a course, in curriculum order, unlinked ones last.
     *
     * @param list<Quiz> $quizzes
     *
     * @return list<QuizSummaryView>
     */
    public function summariesForCourse(int $courseId, array $quizzes): array
    {
        $quizIds = [];

        foreach ($quizzes as $quiz) {
            if ($quiz->id !== null) {
                $quizIds[] = $quiz->id;
            }
        }

        $counts = $this->questions->publishedCounts($quizIds);
        $lessons = $this->lessonsOf($courseId);
        $modules = $this->modulesOf($courseId);
        $order = $this->curriculumOrder($courseId, $lessons, $modules);

        $views = [];

        foreach ($quizzes as $quiz) {
            $views[] = new QuizSummaryView(
                $quiz,
                $this->lessonRef($quiz, $lessons, $modules),
                $counts[$quiz->id ?? 0] ?? 0,
            );
        }

        usort($views, static function (QuizSummaryView $left, QuizSummaryView $right) use ($order): int {
            $leftRank = $order[$left->quiz->lessonId ?? 0] ?? \PHP_INT_MAX;
            $rightRank = $order[$right->quiz->lessonId ?? 0] ?? \PHP_INT_MAX;

            return $leftRank !== $rightRank ? $leftRank <=> $rightRank : ($left->quiz->id ?? 0) <=> ($right->quiz->id ?? 0);
        });

        return $views;
    }

    /**
     * One quiz with its published questions and their authoring definitions.
     */
    public function authoring(Quiz $quiz): QuizSummaryView
    {
        $questions = $quiz->id === null ? [] : $this->questions->publishedForQuiz($quiz->id);
        $lessons = $this->lessonsOf($quiz->courseId);
        $modules = $this->modulesOf($quiz->courseId);

        return new QuizSummaryView(
            $quiz,
            $this->lessonRef($quiz, $lessons, $modules),
            \count($questions),
            array_values(array_filter($questions, static fn (Question $question): bool => $question->id !== null)),
        );
    }

    /**
     * The summary alone, without questions: what a lesson editor shows beside
     * a quiz lesson.
     */
    public function summary(Quiz $quiz): QuizSummaryView
    {
        return new QuizSummaryView(
            $quiz,
            $this->lessonRef($quiz, $this->lessonsOf($quiz->courseId), $this->modulesOf($quiz->courseId)),
            $quiz->id === null ? 0 : ($this->questions->publishedCounts([$quiz->id])[$quiz->id] ?? 0),
        );
    }

    /**
     * @return array<int, Lesson>
     */
    private function lessonsOf(int $courseId): array
    {
        $lessons = [];

        foreach ($this->lessons->byCourse($courseId) as $lesson) {
            $lessons[$lesson->id] = $lesson;
        }

        return $lessons;
    }

    /**
     * @return array<int, Module>
     */
    private function modulesOf(int $courseId): array
    {
        $modules = [];

        foreach ($this->modules->byCourse($courseId) as $module) {
            $modules[$module->id] = $module;
        }

        return $modules;
    }

    /**
     * @param array<int, Lesson> $lessons
     * @param array<int, Module> $modules
     */
    private function lessonRef(Quiz $quiz, array $lessons, array $modules): ?QuizLessonRef
    {
        $lessonId = $quiz->lessonId;

        if ($lessonId === null || !isset($lessons[$lessonId])) {
            return null;
        }

        $lesson = $lessons[$lessonId];
        $module = $modules[$lesson->moduleId] ?? null;

        return new QuizLessonRef(
            $lesson->id,
            $lesson->title,
            $lesson->moduleId,
            $module === null ? '' : $module->title,
        );
    }

    /**
     * @param array<int, Lesson> $lessons
     * @param array<int, Module> $modules
     *
     * @return array<int, int> lesson id to its rank in the curriculum
     */
    private function curriculumOrder(int $courseId, array $lessons, array $modules): array
    {
        $modulePositions = [];

        foreach ($modules as $module) {
            $modulePositions[$module->id] = $module->position;
        }

        $ranked = [];

        foreach ($lessons as $lesson) {
            $ranked[$lesson->id] = [$modulePositions[$lesson->moduleId] ?? \PHP_INT_MAX, $lesson->position, $lesson->id];
        }

        uasort($ranked, static fn (array $left, array $right): int => $left <=> $right);

        $order = [];
        $rank = 0;

        foreach (array_keys($ranked) as $lessonId) {
            $order[$lessonId] = $rank++;
        }

        return $order;
    }
}
