<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assignments\Submission;
use Shondalai\VidyaLMS\Core\Domain\Platform\GradebookRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class DatabaseGradebookRepository implements GradebookRepository
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
    ) {
    }

    public function submissionsForCourse(int $courseId): array
    {
        return $this->database->selectAll(
            Query::from('submissions', 's')
                ->select([
                    's.id',
                    's.assignment_id',
                    's.enrolment_id',
                    's.user_id',
                    's.attempt_number',
                    's.status',
                    's.text_response',
                    's.submitted_at',
                    's.graded_at',
                    's.grader_id',
                    's.points_awarded',
                    's.feedback',
                    's.assessment_json',
                    'a.pass_percentage',
                    'a.rubric_json',
                    'a.max_points AS max_points',
                    'a.allow_resubmission AS allow_resubmission',
                    'l.id AS lesson_id',
                    'l.title AS lesson_title',
                ])
                ->join('assignments', 'a', 's.assignment_id', 'a.id')
                ->join('lessons', 'l', 'a.lesson_id', 'l.id')
                ->where('l.course_id', '=', $courseId)
                ->whereIn('s.status', [Submission::STATUS_SUBMITTED, Submission::STATUS_GRADED])
                ->orderBy('s.submitted_at')
                ->orderBy('s.id'),
        );
    }

    public function attemptAnswersForCourse(int $courseId): array
    {
        return $this->database->selectAll(
            Query::from('attempt_answers', 'aa')
                ->select([
                    'aa.attempt_id',
                    'aa.question_id',
                    'aa.answer',
                    'aa.answered_at',
                    't.user_id',
                    't.status AS attempt_status',
                    't.attempt_number',
                    't.submitted_at',
                    'q.type AS question_type',
                    'q.position AS question_position',
                    'q.points AS question_points',
                    'q.definition AS question_definition',
                    'z.id AS quiz_id',
                    'z.title AS quiz_title',
                    'z.lesson_id AS lesson_id',
                    'l.title AS lesson_title',
                ])
                ->join('attempts', 't', 'aa.attempt_id', 't.id')
                ->join('questions', 'q', 'aa.question_id', 'q.id')
                ->join('quizzes', 'z', 't.quiz_id', 'z.id')
                ->leftJoin('lessons', 'l', 'z.lesson_id', 'l.id')
                ->where('z.course_id', '=', $courseId)
                ->whereIn('t.status', [Attempt::STATUS_SUBMITTED, Attempt::STATUS_GRADED])
                ->orderBy('t.submitted_at')
                ->orderBy('aa.attempt_id')
                ->orderBy('q.position'),
        );
    }

    public function attemptQuestionsForCourse(int $courseId): array
    {
        return $this->database->selectAll(
            Query::from('attempt_questions', 'aq')
                ->select([
                    'aq.attempt_id',
                    'aq.question_id',
                    'aq.position',
                    'aq.points',
                    'aq.scored',
                    'aq.presented',
                    'aq.definition_hash',
                    'aq.answered_status',
                    't.user_id',
                    't.status AS attempt_status',
                    't.attempt_number',
                    't.submitted_at',
                    'q.type AS question_type',
                    'q.definition AS question_definition',
                    'q.definition_version AS question_definition_version',
                    'q.external_scored AS question_external_scored',
                    'q.response_shape AS question_response_shape',
                    'z.id AS quiz_id',
                    'z.title AS quiz_title',
                    'z.lesson_id AS lesson_id',
                    'l.title AS lesson_title',
                ])
                ->join('attempts', 't', 'aq.attempt_id', 't.id')
                ->join('questions', 'q', 'aq.question_id', 'q.id')
                ->join('quizzes', 'z', 't.quiz_id', 'z.id')
                ->leftJoin('lessons', 'l', 'z.lesson_id', 'l.id')
                // The stored answer is not joined here. The port's join takes
                // one column pair and attempt_answers needs two, and the item
                // this query exists to surface is precisely the one with no
                // answer row, so the caller merges the answers it already
                // reads rather than the port growing a compound join for one
                // query.
                ->where('z.course_id', '=', $courseId)
                ->whereIn('t.status', [Attempt::STATUS_SUBMITTED, Attempt::STATUS_GRADED])
                ->orderBy('t.submitted_at')
                ->orderBy('aq.attempt_id')
                ->orderBy('aq.position'),
        );
    }

    public function definitionsByHash(array $hashes): array
    {
        $hashes = array_values(array_unique(array_filter($hashes, static fn (string $hash): bool => $hash !== '')));

        if ($hashes === []) {
            return [];
        }

        $rows = $this->database->selectAll(
            Query::from('question_definitions')
                ->select(['definition_hash', 'type', 'definition_version', 'definition'])
                ->whereIn('definition_hash', $hashes),
        );

        $byHash = [];

        foreach ($rows as $row) {
            $byHash[(string) $row['definition_hash']] = $row;
        }

        return $byHash;
    }

    public function manualGradesFor(array $attemptIds): array
    {
        if ($attemptIds === []) {
            return [];
        }

        return $this->database->selectAll(
            Query::from('attempt_grades')
                ->select(['attempt_id', 'question_id', 'grader_id', 'points_awarded', 'max_points', 'feedback', 'graded_at'])
                ->whereIn('attempt_id', $attemptIds)
                ->whereNotNull('grader_id')
                ->orderBy('id'),
        );
    }

    public function filesFor(array $submissionIds): array
    {
        if ($submissionIds === []) {
            return [];
        }

        return $this->database->selectAll(
            Query::from('submission_files')
                ->select(['submission_id', 'path', 'filename', 'size_bytes', 'mime'])
                ->whereIn('submission_id', $submissionIds)
                ->orderBy('id'),
        );
    }

    public function submissionsWaitingSince(\DateTimeImmutable $cutoff): array
    {
        return $this->database->selectAll(
            Query::from('submissions', 's')
                ->select(['s.id', 'l.course_id AS course_id'])
                ->join('assignments', 'a', 's.assignment_id', 'a.id')
                ->join('lessons', 'l', 'a.lesson_id', 'l.id')
                ->where('s.status', '=', Submission::STATUS_SUBMITTED)
                ->where('s.submitted_at', '<=', $cutoff->format(self::DATETIME_FORMAT)),
        );
    }

    public function attemptsWaitingSince(\DateTimeImmutable $cutoff): array
    {
        return $this->database->selectAll(
            Query::from('attempts', 't')
                ->select(['t.id', 'z.course_id AS course_id'])
                ->join('quizzes', 'z', 't.quiz_id', 'z.id')
                ->where('t.status', '=', Attempt::STATUS_SUBMITTED)
                ->where('t.submitted_at', '<=', $cutoff->format(self::DATETIME_FORMAT)),
        );
    }
}
