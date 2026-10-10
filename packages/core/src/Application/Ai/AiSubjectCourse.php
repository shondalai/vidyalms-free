<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Which course a run's subject actually belongs to.
 *
 * It exists because a scope and a subject are two different facts and the
 * product had a gate for only one of them. A course scope says which course
 * the caller was authorised for; the subject says which row the run is
 * pointed at; and nothing bound the two, so a teacher of one course could
 * name a question of another and every fact provider resolved it, because a
 * fact provider narrows on the subject id it was handed and has no scope of
 * its own to narrow on.
 *
 * The mapping is a read and nothing else: no authorisation, no refusal and no
 * capability. The refusal belongs to the policy that asks, which is where a
 * site can see it, count it and read its reason.
 *
 * **An unknown subject type resolves to null and binds nothing.** A vendor
 * subject type is a real thing the core cannot map, and answering "no course"
 * is the honest reading: the gate then falls through to the capability the
 * front door already required, and a vendor fact provider narrows its own
 * read the way the shipped ones now do.
 */
final class AiSubjectCourse
{
    public const SUBJECT_COURSE = 'course';
    public const SUBJECT_MODULE = 'module';
    public const SUBJECT_LESSON = 'lesson';
    public const SUBJECT_QUIZ = 'quiz';
    public const SUBJECT_QUESTION = 'question';
    public const SUBJECT_ATTEMPT = 'attempt';
    public const SUBJECT_SUBMISSION = 'submission';

    public function __construct(private readonly DatabasePort $database)
    {
    }

    /**
     * The course this subject belongs to, or null when the type names no
     * course or the row does not exist.
     */
    public function of(?string $subjectType, ?int $subjectId): ?int
    {
        if ($subjectType === null || $subjectId === null || $subjectId <= 0) {
            return null;
        }

        return match ($subjectType) {
            self::SUBJECT_COURSE => $subjectId,
            self::SUBJECT_MODULE => $this->columnOf('modules', $subjectId, 'course_id'),
            self::SUBJECT_LESSON => $this->columnOf('lessons', $subjectId, 'course_id'),
            self::SUBJECT_QUIZ => $this->columnOf('quizzes', $subjectId, 'course_id'),
            self::SUBJECT_QUESTION => $this->courseOfQuiz($this->columnOf('questions', $subjectId, 'quiz_id')),
            self::SUBJECT_ATTEMPT => $this->courseOfQuiz($this->columnOf('attempts', $subjectId, 'quiz_id')),
            self::SUBJECT_SUBMISSION => $this->courseOfSubmission($subjectId),
            default => null,
        };
    }

    private function courseOfQuiz(?int $quizId): ?int
    {
        return $quizId === null ? null : $this->columnOf('quizzes', $quizId, 'course_id');
    }

    /**
     * A submission belongs to an assignment, an assignment to a lesson and a
     * lesson to a course, which is three reads rather than a join because the
     * fluent API is the only way these tables are read anywhere else.
     */
    private function courseOfSubmission(int $submissionId): ?int
    {
        $assignmentId = $this->columnOf('submissions', $submissionId, 'assignment_id');

        if ($assignmentId === null) {
            return null;
        }

        $lessonId = $this->columnOf('assignments', $assignmentId, 'lesson_id');

        return $lessonId === null ? null : $this->columnOf('lessons', $lessonId, 'course_id');
    }

    private function columnOf(string $table, int $id, string $column): ?int
    {
        $row = $this->database->selectOne(
            Query::from($table)->select([$column])->where('id', '=', $id)->limit(1),
        );

        $value = $row[$column] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
