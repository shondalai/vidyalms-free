<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assignments;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Events\SubmissionReceived;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class SubmitAssignmentTest extends AssignmentsTestCase
{
    private const LEARNER = 42;

    private int $courseId;
    private int $lessonId;
    private int $enrolmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->courseId = $this->seedCourse();
        $this->lessonId = $this->seedLesson($this->courseId);
        $this->enrolmentId = $this->seedEnrolment(self::LEARNER, $this->courseId);
    }

    /**
     * @param array<string, mixed> $file
     *
     * @return array{path: string, filename: string, size_bytes: int, mime: string}
     */
    private function file(array $file = []): array
    {
        $result = array_merge([
            'path' => 'submissions/42/report.pdf',
            'filename' => 'report.pdf',
            'size_bytes' => 1024,
            'mime' => 'application/pdf',
        ], $file);
        if (is_string($result['path']) && is_int($result['size_bytes']) && $result['size_bytes'] > 0) {
            $this->env->storage->putPrivate($result['path'], str_pad("%PDF-1.4\n", $result['size_bytes'], ' '));
        }
        return $result;
    }

    public function testTextOnlySubmissionIsStoredAndEventEmittedOnce(): void
    {
        $assignmentId = $this->seedAssignment($this->lessonId);

        $submission = $this->submitAssignment()->execute(
            Principal::user(self::LEARNER),
            $this->lessonId,
            'My answer',
            [],
        );

        self::assertNotNull($submission->id);
        self::assertSame(1, $submission->attemptNumber);
        self::assertSame('submitted', $submission->status());

        $row = $this->env->database->selectOne(Query::from('submissions')->where('id', '=', $submission->id));
        self::assertNotNull($row);
        self::assertSame($assignmentId, (int) $row['assignment_id']);
        self::assertSame($this->enrolmentId, (int) $row['enrolment_id']);
        self::assertSame(self::LEARNER, (int) $row['user_id']);
        self::assertSame('submitted', $row['status']);
        self::assertSame('My answer', $row['text_response']);
        self::assertSame($this->now(), $row['submitted_at']);
        self::assertSame($this->now(), $row['created_at']);

        $events = $this->publishedEventsOf(SubmissionReceived::class);
        self::assertCount(1, $events);

        $event = $events[0];
        self::assertInstanceOf(SubmissionReceived::class, $event);
        self::assertSame($submission->id, $event->submissionId);
        self::assertSame($assignmentId, $event->assignmentId);
        self::assertSame($this->lessonId, $event->lessonId);
        self::assertSame($this->courseId, $event->courseId);
        self::assertSame($this->enrolmentId, $event->enrolmentId);
        self::assertSame(self::LEARNER, $event->userId);
        self::assertSame('submission.received:' . $submission->id, $event->idempotencyKey());
    }

    public function testFileSubmissionWritesFileRowsInSameTransaction(): void
    {
        $this->seedAssignment($this->lessonId, [
            'allowed_file_types' => 'pdf,docx',
            'max_file_size_kb' => 10,
            'max_files' => 2,
        ]);

        $submission = $this->submitAssignment()->execute(
            Principal::user(self::LEARNER),
            $this->lessonId,
            null,
            [
                $this->file(['filename' => 'Report.PDF', 'size_bytes' => 10240]),
                $this->file(['path' => 'submissions/42/notes.pdf', 'filename' => 'notes.pdf', 'mime' => 'application/pdf']),
            ],
        );

        self::assertCount(2, $submission->files);

        $fileRows = $this->env->database->selectAll(
            Query::from('submission_files')->where('submission_id', '=', $submission->id),
        );
        self::assertCount(2, $fileRows);
        self::assertSame('Report.PDF', $fileRows[0]['filename']);
        self::assertSame('submissions/42/notes.pdf', $fileRows[1]['path']);
        self::assertSame($this->now(), $fileRows[0]['created_at']);
    }

    public function testClaimedSizeCannotBypassTheAssignmentLimit(): void
    {
        $this->seedAssignment($this->lessonId, ['max_file_size_kb' => 1]);
        $file = $this->file(['size_bytes' => 2048]);
        $file['size_bytes'] = 1;
        $this->expectException(ValidationException::class);
        $this->submitAssignment()->execute(Principal::user(self::LEARNER), $this->lessonId, null, [$file]);
    }

    public function testMissingUploadCannotBeRegisteredAsAFile(): void
    {
        $this->seedAssignment($this->lessonId);
        $file = $this->file();
        $this->env->storage->delete($file['path']);
        $this->expectException(ValidationException::class);
        $this->submitAssignment()->execute(Principal::user(self::LEARNER), $this->lessonId, null, [$file]);
    }

    public function testGuestCannotSubmit(): void
    {
        $this->seedAssignment($this->lessonId);

        $this->expectException(NotAuthorizedException::class);
        $this->submitAssignment()->execute(Principal::guest(), $this->lessonId, 'x', []);
    }

    public function testLessonWithoutAssignmentIsNotFound(): void
    {
        $otherLesson = $this->seedLesson($this->courseId);

        $this->expectException(EntityNotFoundException::class);
        $this->submitAssignment()->execute(Principal::user(self::LEARNER), $otherLesson, 'x', []);
    }

    public function testUserWithoutEnrolmentIsRejected(): void
    {
        $this->seedAssignment($this->lessonId);

        $this->expectException(NotAuthorizedException::class);
        $this->submitAssignment()->execute(Principal::user(77), $this->lessonId, 'x', []);
    }

    public function testSuspendedEnrolmentIsRejected(): void
    {
        $this->seedAssignment($this->lessonId);
        $this->env->database->update('enrolments', ['status' => 'suspended'], ['id' => $this->enrolmentId]);

        $this->expectException(NotAuthorizedException::class);

        try {
            $this->submitAssignment()->execute(Principal::user(self::LEARNER), $this->lessonId, 'x', []);
        } finally {
            self::assertSame(0, $this->env->database->count(Query::from('submissions')));
            self::assertCount(0, $this->publishedEventsOf(SubmissionReceived::class));
        }
    }

    public function testPastDueDateRaisesConflict(): void
    {
        $this->seedAssignment($this->lessonId, ['due_at' => '2026-01-15 09:59:59']);

        $this->expectException(ConflictException::class);
        $this->submitAssignment()->execute(Principal::user(self::LEARNER), $this->lessonId, 'x', []);
    }

    public function testSubmissionExactlyAtDueTimeIsAccepted(): void
    {
        $this->seedAssignment($this->lessonId, ['due_at' => $this->now()]);

        $submission = $this->submitAssignment()->execute(Principal::user(self::LEARNER), $this->lessonId, 'x', []);

        self::assertSame('submitted', $submission->status());
    }

    public function testResubmissionBlockedWhenNotAllowed(): void
    {
        $this->seedAssignment($this->lessonId, ['allow_resubmission' => 0]);
        $learner = Principal::user(self::LEARNER);

        $this->submitAssignment()->execute($learner, $this->lessonId, 'first', []);

        try {
            $this->submitAssignment()->execute($learner, $this->lessonId, 'second', []);
            self::fail('Expected a ConflictException.');
        } catch (ConflictException $exception) {
            self::assertSame(1, $this->env->database->count(Query::from('submissions')));
            self::assertCount(1, $this->publishedEventsOf(SubmissionReceived::class));
        }
    }

    public function testResubmissionAllowedCreatesNewAttemptRow(): void
    {
        $this->seedAssignment($this->lessonId, ['allow_resubmission' => 1]);
        $learner = Principal::user(self::LEARNER);

        $first = $this->submitAssignment()->execute($learner, $this->lessonId, 'first', []);
        $second = $this->submitAssignment()->execute($learner, $this->lessonId, 'second', []);

        self::assertNotSame($first->id, $second->id);
        self::assertSame(1, $first->attemptNumber);
        self::assertSame(2, $second->attemptNumber);
        self::assertSame(2, $this->env->database->count(Query::from('submissions')));

        $events = $this->publishedEventsOf(SubmissionReceived::class);
        self::assertCount(2, $events);
        $keys = array_map(static fn ($event): string => $event->idempotencyKey(), $events);
        self::assertCount(2, array_unique($keys));
    }

    public function testEmptySubmissionIsRejected(): void
    {
        $this->seedAssignment($this->lessonId);

        try {
            $this->submitAssignment()->execute(Principal::user(self::LEARNER), $this->lessonId, '   ', []);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame(0, $this->env->database->count(Query::from('submissions')));
            self::assertCount(0, $this->publishedEventsOf(SubmissionReceived::class));
        }
    }

    public function testDisallowedFileExtensionIsRejected(): void
    {
        $this->seedAssignment($this->lessonId, ['allowed_file_types' => 'pdf']);

        $this->expectException(ValidationException::class);
        $this->submitAssignment()->execute(Principal::user(self::LEARNER), $this->lessonId, null, [
            $this->file(['path' => 'submissions/42/run.exe', 'filename' => 'run.exe', 'mime' => 'application/octet-stream']),
        ]);
    }

    public function testFileWithoutExtensionIsRejectedWhenTypesRestricted(): void
    {
        $this->seedAssignment($this->lessonId, ['allowed_file_types' => 'pdf']);

        $this->expectException(ValidationException::class);
        $this->submitAssignment()->execute(Principal::user(self::LEARNER), $this->lessonId, null, [
            $this->file(['path' => 'submissions/42/README', 'filename' => 'README']),
        ]);
    }

    public function testOversizeFileIsRejected(): void
    {
        $this->seedAssignment($this->lessonId, ['max_file_size_kb' => 1]);

        $this->expectException(ValidationException::class);
        $this->submitAssignment()->execute(Principal::user(self::LEARNER), $this->lessonId, null, [
            $this->file(['size_bytes' => 1025]),
        ]);
    }

    public function testTooManyFilesAreRejected(): void
    {
        $this->seedAssignment($this->lessonId, ['max_files' => 1]);

        $this->expectException(ValidationException::class);
        $this->submitAssignment()->execute(Principal::user(self::LEARNER), $this->lessonId, null, [
            $this->file(),
            $this->file(['path' => 'submissions/42/b.pdf', 'filename' => 'b.pdf']),
        ]);
    }

    public function testFilePathOutsidePrivateStorageIsRejected(): void
    {
        $this->seedAssignment($this->lessonId);

        $this->expectException(ValidationException::class);
        $this->submitAssignment()->execute(Principal::user(self::LEARNER), $this->lessonId, null, [
            $this->file(['path' => 'images/report.pdf']),
        ]);
    }

    public function testZeroByteFileIsRejected(): void
    {
        $this->seedAssignment($this->lessonId);

        $this->expectException(ValidationException::class);
        $this->submitAssignment()->execute(Principal::user(self::LEARNER), $this->lessonId, null, [
            $this->file(['size_bytes' => 0]),
        ]);
    }
}
