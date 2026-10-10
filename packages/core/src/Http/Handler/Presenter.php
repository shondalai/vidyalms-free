<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\AttemptRuntime;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assignments\Submission;
use Shondalai\VidyaLMS\Core\Domain\Assignments\SubmissionFile;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCredit;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportRowProblemPage;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgress;
use Shondalai\VidyaLMS\Core\Provider\Token\CertificateTokenResolver;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;

/**
 * Serialisation of domain objects onto the wire shapes of the /v1 contract.
 * Datetimes leave as UTC "Y-m-d H:i:s" strings, matching the port boundary.
 */
final class Presenter
{
    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    /** RFC 3339 with a literal Z, which is what a wallet and a verifier read. */
    private const INSTANT_FORMAT = 'Y-m-d\TH:i:s\Z';

    private function __construct()
    {
    }

    public static function dateTime(?\DateTimeImmutable $value): ?string
    {
        return $value?->format(self::DATETIME_FORMAT);
    }

    /**
     * An instant in RFC 3339, for the payloads a relying party parses with
     * no SDK of ours in between: the verification response and the status
     * list document.
     *
     * The rest of the contract keeps "Y-m-d H:i:s", which is what
     * NullableDateTime declares and what the generated SDK types. Changing
     * that globally would break every route; leaving the public payloads on
     * it left one credential described two ways, because the credential JSON
     * about the same certificate already uses validFrom and validUntil in
     * this format.
     */
    public static function instant(?\DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new \DateTimeZone('UTC'))->format(self::INSTANT_FORMAT);
    }

    /**
     * A free-form map on the wire, for the fields openapi.yaml declares as
     * "type: object, additionalProperties: true". PHP cannot tell an empty
     * map from an empty list, and json_encode writes [] for both, so an
     * empty payload has to be handed over as an object explicitly or the
     * answer contradicts its own schema and the generated SDK's type.
     *
     * @param array<string, mixed> $map
     *
     * @return array<string, mixed>|\stdClass
     */
    public static function freeFormObject(array $map): array|\stdClass
    {
        return $map === [] ? new \stdClass() : $map;
    }

    /**
     * @return array<string, mixed>
     */
    public static function courseSummary(Course $course): array
    {
        return [
            'id' => $course->id,
            'title' => $course->title,
            'slug' => $course->slug->value,
            'summary' => $course->summary,
            'image' => $course->image,
            'category_id' => $course->categoryId,
            'level' => $course->level,
            'language' => $course->language,
            'price_mode' => $course->priceMode,
            'featured' => $course->featured,
            'published_at' => self::dateTime($course->publishedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function courseDetail(Course $course): array
    {
        return self::courseSummary($course) + [
            'description' => $course->description,
            'visibility' => $course->visibility->value,
            'state' => $course->state->value,
            'completion_rule' => $course->completionRule->value,
            'completion_threshold' => $course->completionThreshold,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function module(Module $module): array
    {
        return [
            'id' => $module->id,
            'title' => $module->title,
            'summary' => $module->summary,
            'position' => $module->position,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function lesson(Lesson $lesson): array
    {
        return [
            'id' => $lesson->id,
            'title' => $lesson->title,
            'slug' => $lesson->slug->value,
            'type' => $lesson->type,
            'summary' => $lesson->summary,
            'duration_seconds' => $lesson->durationSeconds,
            'position' => $lesson->position,
            'free_preview' => $lesson->freePreview,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function enrolment(Enrolment $enrolment): array
    {
        return [
            'id' => $enrolment->id(),
            'user_id' => $enrolment->userId,
            'course_id' => $enrolment->courseId,
            'entitlement_id' => $enrolment->entitlementId,
            'status' => $enrolment->status,
            'enrolled_at' => self::dateTime($enrolment->enrolledAt),
            'completed_at' => self::dateTime($enrolment->completedAt),
            'progress_percent' => $enrolment->progressPercent,
        ];
    }

    /**
     * @param \DateTimeImmutable|null $expiresAt the server deadline of a
     *                                           timed quiz (AttemptDeadline);
     *                                           null when untimed
     *
     * @return array<string, mixed>
     */
    public static function attemptStarted(Attempt $attempt, ?\DateTimeImmutable $expiresAt): array
    {
        return [
            'id' => $attempt->id,
            'quiz_id' => $attempt->quizId,
            'attempt_number' => $attempt->attemptNumber,
            'status' => $attempt->status,
            'started_at' => self::dateTime($attempt->startedAt),
            'expires_at' => self::dateTime($expiresAt),
            'runtime' => self::attemptRuntime($attempt->policy()),
        ];
    }

    /**
     * The frozen policy the runner has to obey.
     *
     * Read from the attempt and never from the quiz, so changing a setting
     * cannot move a learner who is already inside an attempt. It lives here
     * rather than in the assessment presenter because the start route
     * answers before any presenter registry is involved, and one shape in
     * two places would drift.
     *
     * @return array<string, mixed>
     */
    public static function attemptRuntime(AttemptPolicySet $policy): array
    {
        return AttemptRuntime::of($policy);
    }

    /**
     * @return array<string, mixed>
     */
    public static function lessonProgress(LessonProgress $progress): array
    {
        return [
            'lesson_id' => $progress->lessonId,
            'status' => $progress->status(),
            'progress_percent' => $progress->progressPercent(),
            'seconds_spent' => $progress->secondsSpent(),
            'position_seconds' => $progress->positionSeconds(),
            'first_accessed_at' => self::dateTime($progress->firstAccessedAt()),
            'completed_at' => self::dateTime($progress->completedAt()),
            'last_heartbeat_at' => self::dateTime($progress->lastHeartbeatAt()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function submission(Submission $submission): array
    {
        return [
            'id' => $submission->id,
            'assignment_id' => $submission->assignmentId,
            'enrolment_id' => $submission->enrolmentId,
            'user_id' => $submission->userId,
            'attempt_number' => $submission->attemptNumber,
            'status' => $submission->status(),
            'text_response' => $submission->textResponse,
            'submitted_at' => self::dateTime($submission->submittedAt),
            'graded_at' => self::dateTime($submission->gradedAt()),
            'grader_id' => $submission->graderId(),
            'points_awarded' => $submission->pointsAwarded(),
            'feedback' => $submission->feedback(),
            'assessment' => self::freeFormObject($submission->assessment()),
            'files' => array_map(
                static fn (SubmissionFile $file): array => [
                    'path' => $file->path,
                    'filename' => $file->filename,
                    'size_bytes' => $file->sizeBytes,
                    'mime' => $file->mime,
                ],
                $submission->files,
            ),
        ];
    }

    /**
     * The status is what the job says it is, never a guess: "stalled" is the
     * separate answer to "is anything still writing this?", so a screen can
     * stop polling a run whose process died without claiming the importer
     * reported a failure it never reported.
     *
     * "notes" carries the same log with each line's level beside it. A screen
     * showing what needs attention reads that rather than matching "[warning]"
     * against the text, because the text quotes titles the source wrote and a
     * title is not allowed to decide how a line is displayed.
     *
     * @param \DateTimeImmutable|null $now the clock, when the caller has one;
     *                                     without it staleness is not judged
     *
     * @return array<string, mixed>
     */
    public static function importJob(ImportJob $job, ?\DateTimeImmutable $now = null): array
    {
        return [
            'id' => $job->id,
            'importer' => $job->importer,
            'status' => $job->status,
            'source' => $job->sourcePath,
            'options' => self::freeFormObject($job->options),
            'dry_run' => $job->isDryRun(),
            'totals' => self::freeFormObject($job->totals ?? []),
            'log' => $job->log,
            'notes' => array_map(
                static fn (ImportNote $note): array => $note->toArray(),
                ImportNote::parseLog($job->log),
            ),
            'created_by' => $job->createdBy,
            'created_at' => self::dateTime($job->createdAt),
            'started_at' => self::dateTime($job->startedAt),
            'finished_at' => self::dateTime($job->finishedAt),
            'heartbeat_at' => self::dateTime($job->heartbeatAt()),
            'stalled' => $now !== null && $job->hasStalled($now),
            // Null for a site-scoped migration, which is every job written
            // before organisations reached the import pipeline. A console
            // reading this decides whether to offer the organisation's own
            // problems page or the site's.
            'organisation_id' => $job->organisationId,
        ];
    }

    /**
     * One page of an import's row problems.
     *
     * The histogram travels beside the page rather than being counted from
     * it, because it is over the whole job: "the failures are all
     * unmapped_role" is the sentence that makes a forty thousand row import
     * diagnosable in one look, and a count of the twenty-five rows on the
     * current page cannot say it.
     *
     * @return array<string, mixed>
     */
    public static function importRowProblems(ImportRowProblemPage $page): array
    {
        return [
            'items' => $page->items,
            'total' => $page->total,
            'page' => $page->page,
            'per_page' => $page->perPage,
            'by_code' => self::freeFormObject($page->byCode),
            'rejected' => $page->rejected,
        ];
    }

    /**
     * The learner-facing credential shape.
     *
     * The status is resolved against the clock the caller passes rather than
     * read off a column, because expired and not yet valid are derived and a
     * list that read them from a row would go stale the moment the clock
     * moved past an expiry nobody had swept.
     *
     * @param list<CertificateCredit> $credits
     *
     * @return array<string, mixed>
     */
    public static function certificate(
        Certificate $certificate,
        ?\DateTimeImmutable $now = null,
        string $credentialBaseUrl = '',
        string $codePrefix = '',
        array $credits = [],
    ): array {
        return [
            'id' => $certificate->id,
            'course_id' => $certificate->courseId,
            'enrolment_id' => $certificate->enrolmentId,
            'code' => $certificate->code,
            // Stored flat and displayed grouped, so a site that later sets a
            // prefix changes nothing already printed.
            'code_display' => (new CertificateTokenResolver('', $codePrefix))->display($certificate->code),
            // The credential id, separate from the human code: the code is
            // printed and retyped, the uuid is embedded and dereferenced.
            'uuid' => $certificate->uuid,
            'status' => $certificate->statusAt($now ?? $certificate->issuedAt)->value,
            'issued_at' => self::dateTime($certificate->issuedAt),
            // A real column now, populated from the completion event. The
            // card labels its date "Completed" and used to show the issue
            // date under that label, which a manual reissue makes wrong.
            'completed_at' => self::dateTime($certificate->completedAt),
            'valid_from' => self::dateTime($certificate->validFrom),
            'expires_at' => self::dateTime($certificate->expiresAt),
            'revoked_at' => self::dateTime($certificate->revokedAt),
            'supersedes_id' => $certificate->supersedesId,
            'superseded_by_id' => $certificate->supersededById,
            'published' => $certificate->published,
            'credential_url' => $credentialBaseUrl === '' || $certificate->uuid === null
                ? null
                : rtrim($credentialBaseUrl, '/') . '/' . rawurlencode($certificate->uuid),
            'credits' => array_map(
                static fn (CertificateCredit $credit): array => [
                    'scheme' => $credit->scheme,
                    'amount' => $credit->amount,
                    'unit' => $credit->unit,
                    'field_of_study' => $credit->fieldOfStudy,
                ],
                $credits,
            ),
        ];
    }
}
