<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assignments;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityEngine;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityRefusal;
use Shondalai\VidyaLMS\Core\Application\Notification\TransactionalOutbox;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Domain\Assignments\Assignment;
use Shondalai\VidyaLMS\Core\Domain\Assignments\AssignmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Assignments\Submission;
use Shondalai\VidyaLMS\Core\Domain\Assignments\SubmissionFile;
use Shondalai\VidyaLMS\Core\Domain\Assignments\SubmissionRepository;
use Shondalai\VidyaLMS\Core\Domain\Events\SubmissionReceived;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Records a learner's attempt at an assignment lesson. File entries carry
 * metadata of files already placed in private storage through a signed
 * upload; this use case validates and registers them, it never moves bytes.
 */
final class SubmitAssignment
{
    private const PRIVATE_PATH_PREFIX = 'submissions/';

    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly SubmissionRepository $submissions,
        private readonly AccessGuard $guard,
        private readonly EventBus $events,
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        /**
         * Optional so a harness that predates the restriction engine still
         * builds this use case; absent, a gated assignment accepts a
         * submission, which is the same hole the heartbeat and the attempt
         * start had, and is why the kernel always supplies one.
         */
        private readonly ?AvailabilityEngine $engine = null,
        /**
         * Optional for the same reason: absent, the submission is written and
         * dispatched exactly as before and the listener is the only enqueue
         * path.
         */
        private readonly ?TransactionalOutbox $outbox = null,
        private readonly ?\Shondalai\VidyaLMS\Core\Port\Storage\StoragePort $storage = null,
    ) {
    }

    /**
     * The restriction engine's answer for the assignment's own lesson.
     *
     * A cut-off on a cohort schedule and a drip window are both expressed as
     * a leaf on the lesson, so the assignment's own due date is not the only
     * date that can close it, and the design names this use case in the list
     * that must consult the engine.
     *
     * @param array<string, mixed> $enrolment
     */
    private function requireAvailable(Principal $principal, array $enrolment, int $lessonId): void
    {
        $scope = $this->engine?->scopeFor($principal, null, $enrolment);

        if ($scope === null) {
            return;
        }

        $refusal = AvailabilityRefusal::for(
            $scope->entryDecision(GateSubject::TYPE_LESSON, $lessonId),
            'Lesson',
            $lessonId,
        );

        if ($refusal !== null) {
            throw $refusal;
        }
    }

    /** @return array{Assignment, array<string, mixed>} */
    private function contextFor(Principal $principal, int $lessonId, bool $replay = false): array
    {
        $this->guard->requireAuthenticated($principal);

        $assignment = $this->assignments->ofLesson($lessonId);

        if ($assignment === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Assignment', $lessonId);
        }

        $assignmentId = $assignment->id
            ?? throw new \LogicException('A stored assignment must carry an id.');

        $lesson = (new \Shondalai\VidyaLMS\Core\Application\Curriculum\LessonAvailability($this->database))
            ->forLearner($assignment->lessonId);

        if ($lesson === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Lesson', $assignment->lessonId);
        }

        $courseId = (int) $lesson['course_id'];

        $enrolment = $this->database->selectOne(
            Query::from('enrolments')
                ->where('user_id', '=', $principal->userId)
                ->where('course_id', '=', $courseId),
        );

        if ($enrolment === null || !in_array((string) $enrolment['status'], $replay ? ['active', 'completed'] : ['active'], true)) {
            throw new NotAuthorizedException('An active enrolment in the course is required to submit.');
        }

        if (is_string($enrolment['expires_at'] ?? null) && $enrolment['expires_at'] <= $this->clock->now()->format('Y-m-d H:i:s')) {
            throw new NotAuthorizedException('Course access has expired.');
        }
        $this->requireAvailable($principal, $enrolment, $assignment->lessonId);

        return [$assignment, $enrolment];
    }

    public function currentForLearner(Principal $principal, int $lessonId, int $submissionId): Submission
    {
        [$assignment] = $this->contextFor($principal, $lessonId, true);
        $submission = $this->submissions->ofId($submissionId);
        if ($submission === null || $submission->assignmentId !== $assignment->id || $submission->userId !== $principal->userId) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Submission', $submissionId);
        }
        return $submission;
    }

    /**
     * @param list<array<string, mixed>> $files each entry: path, filename, size_bytes, mime
     */
    public function execute(Principal $principal, int $lessonId, ?string $textResponse, array $files): Submission
    {
        [$assignment, $enrolment] = $this->contextFor($principal, $lessonId);
        $assignmentId = $assignment->id ?? throw new \LogicException('A stored assignment must carry an id.');
        $courseId = (int) $enrolment['course_id'];

        $now = $this->clock->now();

        $attempts = $this->submissions->listForUser($assignmentId, $principal->userId);
        $latest = $attempts[0] ?? null;
        $requested = $latest !== null && ($latest->assessment()['decision'] ?? null) === 'resubmit';
        if (($latest?->assessment()['decision'] ?? null) === 'approved') {
            throw new ConflictException('This assignment is already approved.');
        }
        // An instructor's request permits one revision after the original due date.
        // Course/lesson availability still applies, and the new attempt consumes the request.
        if ($assignment->isOverdueAt($now) && !$requested) {
            throw new ConflictException('The assignment due date has passed.');
        }
        if ($latest !== null && !$assignment->allowResubmission && !$requested) {
            throw new ConflictException('The assignment has already been submitted and does not allow resubmission.');
        }

        $text = $textResponse !== null && trim($textResponse) !== '' ? $textResponse : null;
        $fileObjects = $this->validateFiles($assignment, $files, $principal->userId);

        if ($text === null && $fileObjects === []) {
            throw ValidationException::forField('text_response', 'Provide a text response or at least one file.');
        }

        $submission = Submission::submitted(
            $assignmentId,
            (int) $enrolment['id'],
            $principal->userId,
            $this->submissions->maxAttemptNumber($assignmentId, $principal->userId) + 1,
            $text,
            $now,
            $fileObjects,
            ['max_points' => $assignment->maxPoints, 'pass_percentage' => $assignment->passPercentage, 'rubric' => $assignment->rubric],
        );

        // The submission row and its outbox intent commit together, because
        // the instructor mail this event drives is a fact the shipped copy
        // already promises on screen ("here and by email").
        return $this->writeOutbox(function () use (
            $submission,
            $now,
            $assignmentId,
            $assignment,
            $enrolment,
            $principal,
            $courseId,
        ): array {
            $persisted = $this->submissions->add($submission);
            $submissionId = $persisted->id
                ?? throw new \LogicException('The persisted submission must carry an id.');

            return [$persisted, [new SubmissionReceived(
                $now,
                $submissionId,
                $assignmentId,
                $assignment->lessonId,
                (int) $enrolment['id'],
                $principal->userId,
                $courseId,
            )]];
        });
    }

    /**
     * @param callable(): array{0: Submission, 1: list<\Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent>} $operation
     */
    private function writeOutbox(callable $operation): Submission
    {
        if ($this->outbox !== null) {
            return $this->outbox->commit($operation);
        }

        [$submission, $events] = $operation();
        $this->events->dispatchAll($events);

        return $submission;
    }

    /**
     * @param list<array<string, mixed>> $files
     *
     * @return list<SubmissionFile>
     */
    private function validateFiles(Assignment $assignment, array $files, int $userId): array
    {
        if (\count($files) > $assignment->maxFiles) {
            throw ValidationException::forField(
                'files',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('At most %d file(s) may be attached.', $assignment->maxFiles),
            );
        }

        $fileObjects = [];

        foreach (array_values($files) as $index => $file) {
            $field = sprintf('files.%d', $index);

            $path = $file['path'] ?? null;
            $filename = $file['filename'] ?? null;
            $sizeBytes = $file['size_bytes'] ?? null;
            $mime = $file['mime'] ?? null;

            $path = $this->validatePrivatePath($field . '.path', $path, $userId);

            if (!\is_string($filename) || trim($filename) === '') {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($field . '.filename', 'A file name is required.');
            }

            if (!\is_int($sizeBytes) || $sizeBytes < 1) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($field . '.size_bytes', 'The file size must be a positive integer.');
            }

            if (!\is_string($mime) || trim($mime) === '') {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($field . '.mime', 'A mime type is required.');
            }

            try {
                if ($this->storage === null) {
                    throw new \RuntimeException('Storage unavailable.');
                }
                $bytes = $this->storage->readPrivate($path);
                $purpose = \Shondalai\VidyaLMS\Core\Upload\UploadPolicy::free()
                    ->purpose(\Shondalai\VidyaLMS\Core\Upload\UploadPolicy::PURPOSE_ASSIGNMENT);
                if ($purpose === null) {
                    throw new \LogicException('Assignment upload policy unavailable.');
                }
                $actual = \Shondalai\VidyaLMS\Core\Upload\UploadIntake::inspect($purpose, $bytes, basename($path));
            } catch (\Throwable) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($field . '.path', 'Upload a valid assignment file before submitting.');
            }
            $sizeBytes = $actual['size'];
            $mime = $actual['mime'];
            $displayExtension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if ($displayExtension !== $actual['extension']) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($field . '.filename', 'The file name must match the uploaded file type.');
            }
            $fileObject = new SubmissionFile($path, $filename, $sizeBytes, $mime);

            if (!$assignment->acceptsExtension($fileObject->extension())) {
                throw ValidationException::forField(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $field . '.filename',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    sprintf('Files of this type are not accepted. Allowed: %s.', implode(', ', $assignment->allowedFileTypes)),
                );
            }

            $maxBytes = $assignment->maxFileSizeBytes();

            if ($maxBytes !== null && $sizeBytes > $maxBytes) {
                throw ValidationException::forField(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $field . '.size_bytes',
                    sprintf('Files may not exceed %d KB.', (int) $assignment->maxFileSizeKb),
                );
            }

            $fileObjects[] = $fileObject;
        }

        return $fileObjects;
    }

    /**
     * Private storage paths are client-registered after a signed upload, so
     * they are hostile input: each must live under the submitting user's own
     * "submissions/{userId}/" prefix, and traversal syntax is rejected so an
     * adapter resolving the path can never leave that folder.
     */
    private function validatePrivatePath(string $field, mixed $path, int $userId): string
    {
        if (!\is_string($path) || $path === '' || \strlen($path) > 1024) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'A storage path is required.');
        }

        try {
            \Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalStorageSafety::downloadable($path);
        } catch (\InvalidArgumentException) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'Invalid uploaded file path.');
        }

        if (str_contains($path, '\\') || str_contains($path, "\0")) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'File paths must use forward slashes only.');
        }

        $requiredPrefix = self::PRIVATE_PATH_PREFIX . $userId . '/';

        if (!str_starts_with($path, $requiredPrefix)) {
            throw ValidationException::forField(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $field,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('File paths must start with "%s".', $requiredPrefix),
            );
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw ValidationException::forField(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $field,
                    'File paths may not contain empty, "." or ".." segments.',
                );
            }
        }

        return $path;
    }
}
