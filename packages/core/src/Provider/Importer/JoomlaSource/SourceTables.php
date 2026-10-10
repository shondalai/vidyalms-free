<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource;

/**
 * Which of a product's tables each level of a course came out of.
 *
 * The row report stands in for a CSV's file name with the source table, so a
 * problem on a question has to say `guru_questions` and not `guru_program`.
 * The writer knows the levels and the reader knows the table names, and this
 * is the one object that carries the six names across the boundary rather
 * than stamping a table onto every draft.
 *
 * A product with no separate table for a level names the nearest one it has.
 * OSCampus keeps its progress in `oscampus_users_lessons` and has no
 * enrolment table at all, so that is what its learner problems are reported
 * against, which is exactly where an operator would go to look.
 */
final class SourceTables
{
    private function __construct(
        public readonly string $courses,
        public readonly string $modules,
        public readonly string $lessons,
        public readonly string $quizzes,
        public readonly string $questions,
        public readonly string $learners,
    ) {
    }

    public static function of(
        string $courses,
        string $modules,
        string $lessons,
        string $quizzes,
        string $questions,
        string $learners,
    ): self {
        return new self($courses, $modules, $lessons, $quizzes, $questions, $learners);
    }
}
