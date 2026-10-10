<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Assessment\CreateQuiz;
use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateQuiz;
use Shondalai\VidyaLMS\Core\Application\Assignments\ConfigureAssignment;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\ModuleRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * Adds a lesson and whatever its type cannot exist without.
 *
 * Two types carry more than content. A quiz lesson needs a quiz: either an
 * existing unlinked quiz of the same course, named in content.quiz_id, or a
 * new one created here with the lesson's title and default settings. An
 * assignment lesson needs its configuration row, created with the documented
 * defaults and edited afterwards through the assignment route.
 *
 * Doing that here rather than in a handler is the point: an author who added
 * a quiz lesson and got no quiz would have a lesson no learner can open, and
 * a screen that made three calls to avoid it would leave exactly that state
 * behind whenever the second one failed. The whole thing is one transaction.
 */
final class AddAuthoredLesson
{
    private const TYPE_QUIZ = 'quiz';
    private const TYPE_ASSIGNMENT = 'assignment';

    public function __construct(
        private readonly AddLesson $addLesson,
        private readonly CreateQuiz $createQuiz,
        private readonly UpdateQuiz $updateQuiz,
        private readonly ConfigureAssignment $configureAssignment,
        private readonly QuizRepository $quizzes,
        private readonly ModuleRepository $modules,
        private readonly GetAuthoringLesson $authoringLesson,
        private readonly CourseAccess $access,
        private readonly DatabasePort $database,
    ) {
    }

    /**
     * @param array<string, mixed> $content
     */
    public function execute(
        Principal $principal,
        int $moduleId,
        string $title,
        string $type,
        array $content = [],
        ?string $slug = null,
        ?string $summary = null,
        ?int $durationSeconds = null,
        bool $freePreview = false,
        string $state = Lesson::STATE_PUBLISHED,
    ): AuthoringLesson {
        $this->access->requireSignedIn($principal);

        $module = $this->modules->byId($moduleId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Module', $moduleId);

        $this->access->require($principal, $module->courseId, Capability::TEACH);

        $courseId = $module->courseId;

        $lessonId = $this->database->transactional(function () use (
            $principal,
            $moduleId,
            $courseId,
            $title,
            $type,
            $content,
            $slug,
            $summary,
            $durationSeconds,
            $freePreview,
            $state,
        ): int {
            $quiz = null;

            if ($type === self::TYPE_QUIZ) {
                $quiz = $this->quizFor($principal, $courseId, $title, $content);
                $content['quiz_id'] = $quiz->id;
            }

            $lesson = $this->addLesson->execute(
                $principal,
                $moduleId,
                $title,
                $type,
                $content,
                $slug,
                $summary,
                $durationSeconds,
                $freePreview,
                $state,
            );

            if ($quiz !== null) {
                $this->linkQuiz($principal, $quiz, $lesson->id);
            }

            if ($type === self::TYPE_ASSIGNMENT) {
                $this->configureAssignment->execute($principal, $lesson->id);
            }

            return $lesson->id;
        });

        return $this->authoringLesson->reload($lessonId);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function quizFor(Principal $principal, int $courseId, string $title, array $content): Quiz
    {
        $named = $content['quiz_id'] ?? null;

        if ($named === null) {
            return $this->createQuiz->execute($principal, $courseId, $title);
        }

        if (!\is_int($named) || $named <= 0) {
            throw ValidationException::forField('content.quiz_id', 'A positive quiz id is required.');
        }

        $quiz = $this->quizzes->byId($named);

        if ($quiz === null || $quiz->courseId !== $courseId) {
            throw ValidationException::forField('content.quiz_id', 'The quiz must belong to this course.');
        }

        if ($quiz->lessonId !== null) {
            throw ValidationException::forField('content.quiz_id', 'The quiz is already attached to a lesson.');
        }

        return $quiz;
    }

    private function linkQuiz(Principal $principal, Quiz $quiz, int $lessonId): void
    {
        $this->updateQuiz->execute(
            $principal,
            $quiz->id ?? 0,
            $quiz->title,
            $lessonId,
            $quiz->description,
            $quiz->passMark,
            $quiz->maxAttempts,
            $quiz->timeLimitSeconds,
            $quiz->isFinal,
            $quiz->showResults,
        );
    }
}
