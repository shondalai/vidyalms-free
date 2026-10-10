<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Assessment\QuizViews;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Assignments\AssignmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\LessonRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeRegistry;

/**
 * Assembles the editor shape of a lesson: its stored content, whether a
 * provider can still handle it, and the one configuration its type carries
 * (the linked quiz on a quiz lesson, the assignment configuration on an
 * assignment lesson).
 *
 * Holds no authorization; every caller is a use case that already applied the
 * per-course rule.
 */
final class AuthoringLessonView
{
    private const TYPE_QUIZ = 'quiz';
    private const TYPE_ASSIGNMENT = 'assignment';

    public function __construct(
        private readonly LessonRepository $lessons,
        private readonly LessonTypeRegistry $lessonTypes,
        private readonly QuizRepository $quizzes,
        private readonly QuizViews $quizViews,
        private readonly AssignmentRepository $assignments,
        private readonly DatabasePort $database,
    ) {
    }

    public function of(Lesson $lesson): AuthoringLesson
    {
        return new AuthoringLesson(
            $lesson,
            $this->lessons->contentOf($lesson->id) ?? [],
            $this->lessonTypes->has($lesson->type),
            $lesson->type === self::TYPE_QUIZ ? $this->quizOf($lesson) : null,
            $lesson->type === self::TYPE_ASSIGNMENT ? $this->assignments->ofLesson($lesson->id) : null,
        );
    }

    /**
     * @param list<Lesson> $lessons
     *
     * @return list<AuthoringLesson>
     */
    public function ofMany(array $lessons): array
    {
        return array_map($this->of(...), $lessons);
    }

    private function quizOf(Lesson $lesson): ?\Shondalai\VidyaLMS\Core\Application\Assessment\QuizSummaryView
    {
        $quiz = $this->linkedQuiz($lesson);

        return $quiz === null ? null : $this->quizViews->summary($quiz);
    }

    /**
     * A quiz lesson names its quiz in lesson_content, and the quiz row also
     * points back at the lesson. The row is the authority: content can be
     * edited by hand, a foreign key cannot.
     */
    private function linkedQuiz(Lesson $lesson): ?Quiz
    {
        $row = $this->database->selectOne(
            Query::from('quizzes')
                ->select(['id'])
                ->where('lesson_id', '=', $lesson->id)
                ->where('course_id', '=', $lesson->courseId)
                ->orderBy('id'),
        );

        return $row === null ? null : $this->quizzes->byId((int) $row['id']);
    }
}
