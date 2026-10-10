<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assignments;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assignments\Submission;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

final class ListSubmissionsTest extends AssignmentsTestCase
{
    private const LEARNER = 42;
    private const OTHER_LEARNER = 43;
    private const GRADER = 7;

    private int $lessonId;
    private int $assignmentId;

    protected function setUp(): void
    {
        parent::setUp();

        $courseId = $this->seedCourse();
        $this->lessonId = $this->seedLesson($courseId);
        $this->seedEnrolment(self::LEARNER, $courseId);
        $this->seedEnrolment(self::OTHER_LEARNER, $courseId);
        $this->assignmentId = $this->seedAssignment($this->lessonId, ['allow_resubmission' => 1, 'max_points' => 100]);
    }

    public function testGetMySubmissionsReturnsOwnAttemptsNewestFirst(): void
    {
        $learner = Principal::user(self::LEARNER);
        $this->submitAssignment()->execute($learner, $this->lessonId, 'first', []);
        $this->submitAssignment()->execute($learner, $this->lessonId, 'second', []);
        $this->submitAssignment()->execute(Principal::user(self::OTHER_LEARNER), $this->lessonId, 'other', []);

        $mine = $this->getMySubmissions()->execute($learner, $this->assignmentId);

        self::assertCount(2, $mine);
        self::assertSame(2, $mine[0]->attemptNumber);
        self::assertSame(1, $mine[1]->attemptNumber);
        self::assertSame('second', $mine[0]->textResponse);

        foreach ($mine as $submission) {
            self::assertSame(self::LEARNER, $submission->userId);
        }
    }

    public function testGetMySubmissionsRequiresAuthentication(): void
    {
        $this->expectException(NotAuthorizedException::class);
        $this->getMySubmissions()->execute(Principal::guest(), $this->assignmentId);
    }

    public function testListForAssignmentRequiresGradeCapability(): void
    {
        $this->expectException(NotAuthorizedException::class);
        $this->listSubmissionsForAssignment()->execute(Principal::user(self::LEARNER), $this->assignmentId);
    }

    public function testListForAssignmentFiltersByStatusAndPages(): void
    {
        $this->grantStaff(self::GRADER, Capability::GRADE);

        $learner = Principal::user(self::LEARNER);
        $first = $this->submitAssignment()->execute($learner, $this->lessonId, 'a', []);
        $this->submitAssignment()->execute($learner, $this->lessonId, 'b', []);
        $this->submitAssignment()->execute(Principal::user(self::OTHER_LEARNER), $this->lessonId, 'c', []);

        $this->gradeSubmission()->execute(Principal::user(self::GRADER), (int) $first->id, 80.0, null);

        $all = $this->listSubmissionsForAssignment()->execute(Principal::user(self::GRADER), $this->assignmentId);
        self::assertSame(3, $all['total']);
        self::assertCount(3, $all['items']);

        $graded = $this->listSubmissionsForAssignment()->execute(
            Principal::user(self::GRADER),
            $this->assignmentId,
            Submission::STATUS_GRADED,
        );
        self::assertSame(1, $graded['total']);
        self::assertCount(1, $graded['items']);
        self::assertSame($first->id, $graded['items'][0]->id);

        $page = $this->listSubmissionsForAssignment()->execute(
            Principal::user(self::GRADER),
            $this->assignmentId,
            null,
            2,
            2,
        );
        self::assertSame(3, $page['total']);
        self::assertCount(1, $page['items']);
    }

    public function testListForAssignmentRejectsUnknownStatus(): void
    {
        $this->grantStaff(self::GRADER, Capability::GRADE);

        $this->expectException(ValidationException::class);
        $this->listSubmissionsForAssignment()->execute(Principal::user(self::GRADER), $this->assignmentId, 'archived');
    }

    public function testListForAssignmentRejectsInvalidPaging(): void
    {
        $this->grantStaff(self::GRADER, Capability::GRADE);

        $this->expectException(ValidationException::class);
        $this->listSubmissionsForAssignment()->execute(Principal::user(self::GRADER), $this->assignmentId, null, 0);
    }

    public function testListForAssignmentLoadsFilesForEachSubmission(): void
    {
        $this->grantStaff(self::GRADER, Capability::GRADE);
        $this->env->storage->putPrivate('submissions/42/a.pdf', '%PDF-1.4\nTest document');

        $this->submitAssignment()->execute(Principal::user(self::LEARNER), $this->lessonId, null, [[
            'path' => 'submissions/42/a.pdf',
            'filename' => 'a.pdf',
            'size_bytes' => 100,
            'mime' => 'application/pdf',
        ]]);

        $result = $this->listSubmissionsForAssignment()->execute(Principal::user(self::GRADER), $this->assignmentId);

        self::assertCount(1, $result['items']);
        self::assertCount(1, $result['items'][0]->files);
        self::assertSame('submissions/42/a.pdf', $result['items'][0]->files[0]->path);
        self::assertSame('a.pdf', $result['items'][0]->files[0]->filename);
    }
}
