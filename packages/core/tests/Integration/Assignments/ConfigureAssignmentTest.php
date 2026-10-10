<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assignments;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class ConfigureAssignmentTest extends AssignmentsTestCase
{
    private const TEACHER = 10;

    public function testTeacherConfiguresAnAssignmentLesson(): void
    {
        $this->grantStaff(self::TEACHER, Capability::TEACH);
        $lessonId = $this->seedLesson($this->seedCourse());

        $assignment = $this->configureAssignment()->execute(
            Principal::user(self::TEACHER),
            $lessonId,
            instructions: 'Write an essay.',
            maxPoints: 50.0,
            allowedFileTypes: 'PDF, .docx',
            maxFileSizeKb: 2048,
            maxFiles: 3,
            dueAt: '2026-02-01 00:00:00',
            allowResubmission: true,
        );

        self::assertNotNull($assignment->id);
        self::assertSame(['pdf', 'docx'], $assignment->allowedFileTypes);

        $row = $this->env->database->selectOne(Query::from('assignments')->where('lesson_id', '=', $lessonId));
        self::assertNotNull($row);
        self::assertSame('Write an essay.', $row['instructions']);
        self::assertEquals(50, $row['max_points']);
        self::assertSame('pdf,docx', $row['allowed_file_types']);
        self::assertSame(2048, (int) $row['max_file_size_kb']);
        self::assertSame(3, (int) $row['max_files']);
        self::assertSame('2026-02-01 00:00:00', $row['due_at']);
        self::assertSame(1, (int) $row['allow_resubmission']);
        self::assertSame($this->now(), $row['created_at']);
        self::assertSame($this->now(), $row['updated_at']);
    }

    public function testConfiguringTwiceUpdatesTheSameRow(): void
    {
        $this->grantStaff(self::TEACHER, Capability::TEACH);
        $lessonId = $this->seedLesson($this->seedCourse());
        $teacher = Principal::user(self::TEACHER);

        $first = $this->configureAssignment()->execute($teacher, $lessonId, maxPoints: 40.0);
        $second = $this->configureAssignment()->execute($teacher, $lessonId, maxPoints: 80.0, allowResubmission: true);

        self::assertSame($first->id, $second->id);
        self::assertSame(1, $this->env->database->count(Query::from('assignments')->where('lesson_id', '=', $lessonId)));

        $row = $this->env->database->selectOne(Query::from('assignments')->where('lesson_id', '=', $lessonId));
        self::assertNotNull($row);
        self::assertEquals(80, $row['max_points']);
        self::assertSame(1, (int) $row['allow_resubmission']);
    }

    public function testGuestIsDenied(): void
    {
        $lessonId = $this->seedLesson($this->seedCourse());

        $this->expectException(NotAuthorizedException::class);
        $this->configureAssignment()->execute(Principal::guest(), $lessonId);
    }

    public function testUserWithoutTeachCapabilityIsDenied(): void
    {
        $lessonId = $this->seedLesson($this->seedCourse());

        $this->expectException(NotAuthorizedException::class);
        $this->configureAssignment()->execute(Principal::user(self::TEACHER), $lessonId);
    }

    public function testUnknownLessonIsRejected(): void
    {
        $this->grantStaff(self::TEACHER, Capability::TEACH);

        $this->expectException(EntityNotFoundException::class);
        $this->configureAssignment()->execute(Principal::user(self::TEACHER), 9999);
    }

    public function testNonAssignmentLessonIsRejected(): void
    {
        $this->grantStaff(self::TEACHER, Capability::TEACH);
        $lessonId = $this->seedLesson($this->seedCourse(), 'video');

        $this->expectException(ValidationException::class);
        $this->configureAssignment()->execute(Principal::user(self::TEACHER), $lessonId);
    }

    public function testZeroMaxPointsIsRejected(): void
    {
        $this->grantStaff(self::TEACHER, Capability::TEACH);
        $lessonId = $this->seedLesson($this->seedCourse());

        try {
            $this->configureAssignment()->execute(Principal::user(self::TEACHER), $lessonId, maxPoints: 0.0);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('max_points', $exception->errors);
        }
    }

    public function testZeroMaxFilesIsRejected(): void
    {
        $this->grantStaff(self::TEACHER, Capability::TEACH);
        $lessonId = $this->seedLesson($this->seedCourse());

        try {
            $this->configureAssignment()->execute(Principal::user(self::TEACHER), $lessonId, maxFiles: 0);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('max_files', $exception->errors);
        }
    }

    public function testMalformedAllowedFileTypesAreRejected(): void
    {
        $this->grantStaff(self::TEACHER, Capability::TEACH);
        $lessonId = $this->seedLesson($this->seedCourse());

        try {
            $this->configureAssignment()->execute(
                Principal::user(self::TEACHER),
                $lessonId,
                allowedFileTypes: 'pdf,,d ocx',
            );
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('allowed_file_types', $exception->errors);
        }
    }

    public function testMalformedDueDateIsRejected(): void
    {
        $this->grantStaff(self::TEACHER, Capability::TEACH);
        $lessonId = $this->seedLesson($this->seedCourse());

        try {
            $this->configureAssignment()->execute(Principal::user(self::TEACHER), $lessonId, dueAt: 'tomorrow');
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('due_at', $exception->errors);
        }
    }

    public function testNegativeMaxFileSizeIsRejected(): void
    {
        $this->grantStaff(self::TEACHER, Capability::TEACH);
        $lessonId = $this->seedLesson($this->seedCourse());

        try {
            $this->configureAssignment()->execute(Principal::user(self::TEACHER), $lessonId, maxFileSizeKb: 0);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('max_file_size_kb', $exception->errors);
        }
    }
}
