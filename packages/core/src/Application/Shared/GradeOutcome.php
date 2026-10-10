<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Shared;

/**
 * What a manual grade did, read back after the server-side listeners ran.
 *
 * A grader needs to know this: grading the last assignment of a course can
 * complete the course and issue a certificate, and a screen that answered
 * only "graded" would leave the person wondering whether any of that
 * happened.
 */
final class GradeOutcome
{
    public function __construct(
        public readonly bool $lessonCompleted,
        public readonly bool $enrolmentCompleted,
    ) {
    }
}
