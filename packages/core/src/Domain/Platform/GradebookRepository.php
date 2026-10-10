<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

/**
 * The rows behind a course gradebook, read across contexts.
 *
 * Two sources feed one queue: assignment submissions and the answers of
 * submitted quiz attempts. Which quiz answers actually need a human is not a
 * question rows can answer, because the question type provider decides it, so
 * this returns the candidates and the use case asks the registry. Everything
 * here is read only; the Assessment and Assignments contexts stay the only
 * writers of their own tables.
 */
interface GradebookRepository
{
    /**
     * Submissions of a course that a human has looked at or must look at:
     * status submitted or graded, with their assignment and lesson.
     *
     * Row keys: id, assignment_id, enrolment_id, user_id, attempt_number,
     * status, text_response, submitted_at, graded_at, grader_id,
     * points_awarded, feedback, max_points, allow_resubmission, lesson_id,
     * lesson_title.
     *
     * @return list<array<string, mixed>>
     */
    public function submissionsForCourse(int $courseId): array;

    /**
     * The stored answers of every submitted or graded attempt of a course,
     * with the question that was answered and the quiz it belongs to.
     *
     * Row keys: attempt_id, question_id, answer, answered_at, user_id,
     * attempt_status, attempt_number, submitted_at, question_type,
     * question_position, question_points, question_definition, quiz_id,
     * quiz_title, lesson_id, lesson_title.
     *
     * @return list<array<string, mixed>>
     */
    public function attemptAnswersForCourse(int $courseId): array;

    /**
     * The frozen served set of every submitted or graded attempt of a
     * course, left joined to the stored answer.
     *
     * The answers query cannot replace this, and the difference is the whole
     * point: an item the learner left blank has no answer row, so an essay
     * nobody opened never reached the grading queue at all and the attempt
     * went straight to graded with the pass flag settled. The served set has
     * a row whether or not the learner wrote anything.
     *
     * The stored answer is not joined: the port's join takes one column pair
     * and attempt_answers needs two, so the caller merges the answers it
     * already reads.
     *
     * Row keys: attempt_id, question_id, position, points, scored,
     * presented, definition_hash, answered_status, user_id, attempt_status,
     * attempt_number, submitted_at, question_type, question_definition,
     * question_definition_version, question_external_scored,
     * question_response_shape, quiz_id, quiz_title, lesson_id, lesson_title.
     *
     * @return list<array<string, mixed>>
     */
    public function attemptQuestionsForCourse(int $courseId): array;

    /**
     * The frozen definitions the given manifest rows point at, keyed by hash.
     *
     * The queue has to decide pending-human from what the attempt was served,
     * not from the live questions row: an authoring edit after submission
     * would otherwise change the answer, and the two use cases that ask the
     * same question of the same attempt would disagree. attempt_questions
     * already carries definition_hash; this is the other half of it.
     *
     * Row keys: definition_hash, type, definition_version, definition.
     *
     * @param list<string> $hashes
     *
     * @return array<string, array<string, mixed>>
     */
    public function definitionsByHash(array $hashes): array;

    /**
     * Manual grade rows (grader_id not null) for the given attempts, oldest
     * first, so the caller keeps the latest per question and the history
     * behind it.
     *
     * Row keys: attempt_id, question_id, grader_id, points_awarded,
     * max_points, feedback, graded_at.
     *
     * @param list<int> $attemptIds
     *
     * @return list<array<string, mixed>>
     */
    public function manualGradesFor(array $attemptIds): array;

    /**
     * Row keys: submission_id, path, filename, size_bytes, mime.
     *
     * @param list<int> $submissionIds
     *
     * @return list<array<string, mixed>>
     */
    public function filesFor(array $submissionIds): array;

    /**
     * Submissions waiting for a grade site wide, submitted at or before the
     * cutoff. Row keys: id, course_id.
     *
     * @return list<array<string, mixed>>
     */
    public function submissionsWaitingSince(\DateTimeImmutable $cutoff): array;

    /**
     * Attempts still waiting for a human site wide (status submitted),
     * submitted at or before the cutoff. Row keys: id, course_id.
     *
     * @return list<array<string, mixed>>
     */
    public function attemptsWaitingSince(\DateTimeImmutable $cutoff): array;
}
