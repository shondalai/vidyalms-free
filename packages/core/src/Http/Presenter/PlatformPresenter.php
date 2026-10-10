<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Presenter;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateRecord;
use Shondalai\VidyaLMS\Core\Application\Platform\GradebookFile;
use Shondalai\VidyaLMS\Core\Application\Platform\GradebookItem;
use Shondalai\VidyaLMS\Core\Application\Platform\AdminLearner;
use Shondalai\VidyaLMS\Core\Application\Platform\LearnerRecord;
use Shondalai\VidyaLMS\Core\Application\Platform\PlatformSettings;
use Shondalai\VidyaLMS\Core\Application\Platform\SiteOverviewReport;
use Shondalai\VidyaLMS\Core\Application\Platform\TopCourse;
use Shondalai\VidyaLMS\Core\Application\Progress\LearnerCourseProgress;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgress;
use Shondalai\VidyaLMS\Core\Http\Handler\Presenter;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;

/**
 * The wire shapes of the site-wide screens: the gradebook, one person's
 * record across the site, the platform facts and the dashboard.
 *
 * Gradebook files travel as a signed, expiring URL and never as a path, which
 * is the opposite of authoring content and is the second rule of the surface
 * that lives in a presenter.
 */
final class PlatformPresenter
{
    /** How long a grader's link to a submitted file stays good. */
    public const FILE_URL_TTL_SECONDS = 3600;

    public const SHAPE_GRADEBOOK_ITEM = 'gradebook_item';
    public const SHAPE_ADMIN_LEARNER = 'admin_learner';
    public const SHAPE_LEARNER_RECORD = 'learner_record';
    public const SHAPE_LEARNER_COURSE_PROGRESS = 'learner_course_progress';
    public const SHAPE_PLATFORM_SETTINGS = 'platform_settings';
    public const SHAPE_SITE_OVERVIEW = 'site_overview';

    public function __construct(
        private readonly Decorated $decorated,
        private readonly SharedPresenter $shared,
        private readonly AccessPresenter $access,
        private readonly CredentialsPresenter $credentials,
        private readonly AssessmentPresenter $assessment,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function learnerCourseProgress(LearnerCourseProgress $progress): array
    {
        $summary = $progress->summary;

        return $this->decorated->apply(self::SHAPE_LEARNER_COURSE_PROGRESS, [
            'enrolment' => $this->access->enrolmentRecord($progress->enrolment),
            'summary' => $summary === null ? null : [
                'lessons_total' => $summary->lessonsTotal,
                'lessons_completed' => $summary->lessonsCompleted,
                'percent' => $summary->percent,
                'quiz_average' => $summary->quizAverage,
                'final_quiz_passed' => $summary->finalQuizPassed,
                'computed_at' => Presenter::dateTime($summary->computedAt),
            ],
            'lessons' => array_map(
                static fn (LessonProgress $lesson): array => Presenter::lessonProgress($lesson),
                $progress->lessons,
            ),
        ], $progress);
    }

    /**
     * @return array<string, mixed>
     */
    public function learnerRecord(LearnerRecord $record, ?string $verificationBaseUrl): array
    {
        return $this->decorated->apply(self::SHAPE_LEARNER_RECORD, [
            'user' => $this->shared->userAccount($record->user),
            'enrolments' => array_map($this->access->enrolmentRecord(...), $record->enrolments),
            'certificates' => array_map(
                fn (CertificateRecord $certificate): array => $this->credentials->certificateRecord(
                    $certificate,
                    $verificationBaseUrl,
                ),
                $record->certificates,
            ),
        ], $record);
    }

    /**
     * @return array<string, mixed>
     */
    public function adminLearner(AdminLearner $learner): array
    {
        $summary = $learner->summary;

        return $this->decorated->apply(self::SHAPE_ADMIN_LEARNER, [
            'user_id' => $summary->userId,
            'user' => $learner->user === null ? null : $this->shared->userAccount($learner->user),
            'course_count' => $summary->courseCount,
            'active_course_count' => $summary->activeCourseCount,
            'completed_course_count' => $summary->completedCourseCount,
            'average_progress' => $summary->averageProgress,
            'last_activity_at' => Presenter::dateTime($summary->lastActivityAt),
            'last_enrolled_at' => Presenter::dateTime($summary->lastEnrolledAt),
        ], $learner);
    }

    /**
     * @return array<string, mixed>
     */
    public function gradebookItem(GradebookItem $item, StoragePort $storage): array
    {
        return $this->decorated->apply(self::SHAPE_GRADEBOOK_ITEM, [
            'key' => $item->key,
            'type' => $item->type,
            'status' => $item->status,
            'learner' => $this->shared->learnerRef($item->learnerId, $item->learnerName),
            'course_id' => $item->courseId,
            'lesson_id' => $item->lessonId,
            'lesson_title' => $item->lessonTitle,
            'waiting_since' => Presenter::dateTime($item->waitingSince),
            'grade' => $item->grade === null ? null : $this->assessment->manualGrade($item->grade),
            'submission' => $item->submission === null ? null : [
                'id' => $item->submission->id,
                'assignment_id' => $item->submission->assignmentId,
                'enrolment_id' => $item->submission->enrolmentId,
                'attempt_number' => $item->submission->attemptNumber,
                'text_response' => $item->submission->textResponse,
                'submitted_at' => Presenter::dateTime($item->submission->submittedAt),
                'max_points' => $item->submission->maxPoints,
                'allow_resubmission' => $item->submission->allowResubmission,
                'assessment' => $item->submission->assessment,
                'files' => array_map(
                    static fn (GradebookFile $file): array => [
                        'filename' => $file->filename,
                        'size_bytes' => $file->sizeBytes,
                        'mime' => $file->mime,
                        'url' => $storage->signedUrl($file->path, self::FILE_URL_TTL_SECONDS),
                    ],
                    $item->submission->files,
                ),
            ],
            'quiz_answer' => $item->quizAnswer === null ? null : [
                'attempt_id' => $item->quizAnswer->attemptId,
                'quiz_id' => $item->quizAnswer->quizId,
                'quiz_title' => $item->quizAnswer->quizTitle,
                'attempt_number' => $item->quizAnswer->attemptNumber,
                'question' => [
                    'id' => $item->quizAnswer->questionId,
                    'position' => $item->quizAnswer->questionPosition,
                    'type' => $item->quizAnswer->questionType,
                    'points' => $item->quizAnswer->questionPoints,
                    'presentation' => Presenter::freeFormObject($item->quizAnswer->presentation),
                ],
                'answer' => $item->quizAnswer->answer,
                'answered_at' => Presenter::dateTime($item->quizAnswer->answeredAt),
                // The rubric the rater marks against, descriptors included,
                // or null on a site with no rubric provider. The contract
                // has declared this member since rubrics were designed and
                // the shipped queue already posts the criteria shape back,
                // so emitting nothing left the grading screen unable to mark
                // the answers it was showing.
                'rubric' => $item->quizAnswer->rubric,
            ],
        ], $item);
    }

    /**
     * @return array<string, mixed>
     */
    public function platformSettings(PlatformSettings $settings): array
    {
        return $this->decorated->apply(self::SHAPE_PLATFORM_SETTINGS, [
            'certificate_template' => $settings->certificateTemplateId === null ? null : [
                'id' => $settings->certificateTemplateId,
                'name' => $settings->certificateTemplateName ?? '',
                'renderer' => $settings->certificateTemplateRenderer ?? '',
            ],
            'verification_base_url' => $settings->verificationBaseUrl,
            'heartbeat' => [
                'interval_seconds' => $settings->heartbeatIntervalSeconds,
                'max_seconds_per_heartbeat' => $settings->maxSecondsPerHeartbeat,
            ],
            'expiry_sweep' => [
                'schedule' => $settings->expirySweepSchedule,
                'last_run_at' => Presenter::dateTime($settings->expirySweepLastRunAt),
            ],
            'commerce' => [
                // The singular pair stays and carries the resolved default,
                // so every reader written against it keeps working; the list
                // is what a site with two sources actually has.
                'source' => $settings->commerceSource,
                'label' => $settings->commerceLabel,
                'sources' => $settings->commerceSources,
            ],
            'self_enrolment' => $settings->selfEnrolment,
            'host_settings_url' => $settings->hostSettingsUrl,
            'configuration' => $settings->configuration,
            'publish_learner_name' => $settings->publishLearnerName,
            'issuer' => [
                // What an administrator typed, so the screen can show an
                // empty field as empty rather than showing the derived value
                // as if somebody had typed it.
                'name' => $settings->issuer['name'] ?? null,
                'url' => $settings->issuer['url'] ?? null,
                'image' => $settings->issuer['image'] ?? null,
                // What every credential and the verification page actually
                // report, which is the stored record over the host facts.
                'resolved' => [
                    'id' => $settings->resolvedIssuer['id'],
                    'name' => $settings->resolvedIssuer['name'],
                    'url' => $settings->resolvedIssuer['url'] === '' ? null : $settings->resolvedIssuer['url'],
                    'image' => $settings->resolvedIssuer['image'] === '' ? null : $settings->resolvedIssuer['image'],
                ],
            ],
            'background_actor' => [
                // Null is the stated configuration gap, so the screen can say
                // "nobody" rather than showing a zero as if somebody had
                // chosen one. may_grant is beside it because an account that
                // cannot enrol other people is a setting whose only effect is
                // a deferred order hours later.
                'user_id' => $settings->backgroundActorUserId,
                'name' => $settings->backgroundActorName,
                'may_grant' => $settings->backgroundActorMayGrant,
            ],
        ], $settings);
    }

    /**
     * @return array<string, mixed>
     */
    public function siteOverview(SiteOverviewReport $report): array
    {
        return $this->decorated->apply(self::SHAPE_SITE_OVERVIEW, [
            'courses' => [
                'published' => $report->publishedCourses,
                'draft' => $report->draftCourses,
                'archived' => $report->archivedCourses,
            ],
            'enrolments' => [
                'active' => $report->activeEnrolments,
                'suspended' => $report->suspendedEnrolments,
                'completed' => $report->completedEnrolments,
            ],
            'completions' => [
                'total' => $report->completedEnrolments,
                'this_month' => $report->completionsThisMonth,
            ],
            'certificates' => [
                'issued' => $report->certificatesIssued,
                'revoked' => $report->certificatesRevoked,
            ],
            'attention' => [
                'grading_waiting' => [
                    'submissions' => $report->waitingSubmissions,
                    'attempts' => $report->waitingAttempts,
                    'courses' => $report->waitingCourses,
                    'older_than_days' => $report->waitingOlderThanDays,
                ],
                'expiring_entitlements' => [
                    'count' => $report->expiringEntitlements,
                    'within_days' => $report->expiringWithinDays,
                ],
                'expiry_sweep' => [
                    'last_run_at' => Presenter::dateTime($report->expirySweepLastRunAt),
                    'schedule' => $report->expirySweepSchedule,
                ],
                'lessons_without_provider' => [
                    'count' => $report->lessonsWithoutProvider,
                    'types' => $report->lessonTypesWithoutProvider,
                ],
            ],
            'top_courses' => array_map(
                fn (TopCourse $top): array => $this->shared->courseRef($top->course)
                    + [
                        'active_count' => $top->activeCount,
                        'enrolled_count' => $top->enrolledCount,
                        'completed_count' => $top->completedCount,
                        'lesson_count' => $top->lessonCount,
                    ],
                $report->topCourses,
            ),
            'generated_at' => Presenter::dateTime($report->generatedAt),
            'activity' => $report->activity,
        ], $report);
    }
}
