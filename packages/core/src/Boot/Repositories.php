<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Boot;

use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Assignments\AssignmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Assignments\SubmissionRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\LessonRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\ModuleRepository;
use Shondalai\VidyaLMS\Core\Domain\Progress\CourseProgressRepository;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgressRepository;

/**
 * Alternative repository implementations a package hands the kernel at boot.
 *
 * Everything left null is the free build's own, so a caller that passes
 * nothing gets exactly what it always got. This is the seam for the thing the
 * registries cannot express: a package that needs the same use cases reading
 * different rows.
 *
 * Pro's group scoping is the case this exists for. A group-scoped course
 * repository answers the courses the caller's group covers and nothing else,
 * and every use case above it goes on working unchanged, because a use case
 * depends on the interface. Without this it would have to reimplement the use
 * cases, or the free build would have to grow a group concept it does not
 * have.
 *
 * A replacement must honour the interface exactly. Narrowing what a read
 * answers is the intended use; changing what a write means is not, and the
 * core has no way to police it, which is why this is a boot-time decision by
 * the site's own installed code rather than anything a request can reach.
 */
final class Repositories
{
    public function __construct(
        public readonly ?CourseRepository $courses = null,
        public readonly ?ModuleRepository $modules = null,
        public readonly ?LessonRepository $lessons = null,
        public readonly ?QuizRepository $quizzes = null,
        public readonly ?QuestionRepository $questions = null,
        public readonly ?AttemptRepository $attempts = null,
        public readonly ?AssignmentRepository $assignments = null,
        public readonly ?SubmissionRepository $submissions = null,
        public readonly ?EntitlementRepository $entitlements = null,
        public readonly ?EnrolmentRepository $enrolments = null,
        public readonly ?LessonProgressRepository $lessonProgress = null,
        public readonly ?CourseProgressRepository $courseProgress = null,
        public readonly ?CertificateRepository $certificates = null,
        public readonly ?CertificateTemplateRepository $certificateTemplates = null,
    ) {
    }
}
