<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assignments;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Events\SubmissionGraded;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class GradeSubmissionTest extends AssignmentsTestCase
{
    private const LEARNER = 42;
    private const GRADER = 7;

    private int $courseId;
    private int $lessonId;
    private int $assignmentId;
    private int $submissionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->courseId = $this->seedCourse();
        $this->lessonId = $this->seedLesson($this->courseId);
        $this->seedEnrolment(self::LEARNER, $this->courseId);
        $this->assignmentId = $this->seedAssignment($this->lessonId, ['max_points' => 50]);

        $submission = $this->submitAssignment()->execute(
            Principal::user(self::LEARNER),
            $this->lessonId,
            'My answer',
            [],
        );

        $this->submissionId = (int) $submission->id;
        $this->env->eventBridge->published = [];
    }

    public function testGraderGradesASubmittedSubmission(): void
    {
        $this->grantStaff(self::GRADER, Capability::GRADE);
        $this->env->clock->advance('PT1H');

        $graded = $this->gradeSubmission()->execute(
            Principal::user(self::GRADER),
            $this->submissionId,
            45.5,
            'Good work',
        );

        self::assertSame('graded', $graded->status());

        $row = $this->env->database->selectOne(Query::from('submissions')->where('id', '=', $this->submissionId));
        self::assertNotNull($row);
        self::assertSame('graded', $row['status']);
        self::assertEquals(45.5, $row['points_awarded']);
        self::assertSame('Good work', $row['feedback']);
        self::assertSame(self::GRADER, (int) $row['grader_id']);
        self::assertSame('2026-01-15 11:00:00', $row['graded_at']);

        $events = $this->publishedEventsOf(SubmissionGraded::class);
        self::assertCount(1, $events);

        $event = $events[0];
        self::assertInstanceOf(SubmissionGraded::class, $event);
        self::assertSame($this->submissionId, $event->submissionId);
        self::assertSame($this->assignmentId, $event->assignmentId);
        self::assertSame($this->lessonId, $event->lessonId);
        self::assertSame($this->courseId, $event->courseId);
        self::assertSame(self::LEARNER, $event->userId);
        self::assertEqualsWithDelta(45.5, $event->pointsAwarded, 0.001);
        self::assertEqualsWithDelta(50.0, $event->maxPoints, 0.001);
        self::assertSame(
            'submission.graded:' . $this->submissionId . ':20260115110000000000',
            $event->idempotencyKey(),
        );
    }

    public function testBoundaryPointsAreAccepted(): void
    {
        $this->grantStaff(self::GRADER, Capability::GRADE);

        $graded = $this->gradeSubmission()->execute(Principal::user(self::GRADER), $this->submissionId, 0.0, null);

        self::assertEqualsWithDelta(0.0, (float) $graded->pointsAwarded(), 0.001);
    }

    public function testMaximumPointsAreAccepted(): void
    {
        $this->grantStaff(self::GRADER, Capability::GRADE);

        $graded = $this->gradeSubmission()->execute(Principal::user(self::GRADER), $this->submissionId, 50.0, null);

        self::assertEqualsWithDelta(50.0, (float) $graded->pointsAwarded(), 0.001);
    }

    public function testNegativePointsAreRejected(): void
    {
        $this->grantStaff(self::GRADER, Capability::GRADE);

        try {
            $this->gradeSubmission()->execute(Principal::user(self::GRADER), $this->submissionId, -1.0, null);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('points_awarded', $exception->errors);
            self::assertCount(0, $this->publishedEventsOf(SubmissionGraded::class));
        }
    }

    public function testPointsAboveMaximumAreRejected(): void
    {
        $this->grantStaff(self::GRADER, Capability::GRADE);

        $this->expectException(ValidationException::class);
        $this->gradeSubmission()->execute(Principal::user(self::GRADER), $this->submissionId, 50.01, null);
    }

    public function testGradingTwiceRaisesConflict(): void
    {
        $this->grantStaff(self::GRADER, Capability::GRADE);
        $this->gradeSubmission()->execute(Principal::user(self::GRADER), $this->submissionId, 40.0, null);

        try {
            $this->gradeSubmission()->execute(Principal::user(self::GRADER), $this->submissionId, 45.0, null);
            self::fail('Expected a ConflictException.');
        } catch (ConflictException $exception) {
            self::assertCount(1, $this->publishedEventsOf(SubmissionGraded::class));

            $row = $this->env->database->selectOne(Query::from('submissions')->where('id', '=', $this->submissionId));
            self::assertNotNull($row);
            self::assertEquals(40, $row['points_awarded']);
        }
    }

    public function testDraftSubmissionCannotBeGraded(): void
    {
        $this->grantStaff(self::GRADER, Capability::GRADE);
        $this->env->database->update('submissions', ['status' => 'draft'], ['id' => $this->submissionId]);

        $this->expectException(ConflictException::class);
        $this->gradeSubmission()->execute(Principal::user(self::GRADER), $this->submissionId, 10.0, null);
    }

    public function testUserWithoutGradeCapabilityIsDenied(): void
    {
        $this->expectException(NotAuthorizedException::class);
        $this->gradeSubmission()->execute(Principal::user(self::GRADER), $this->submissionId, 10.0, null);
    }

    public function testGuestIsDenied(): void
    {
        $this->expectException(NotAuthorizedException::class);
        $this->gradeSubmission()->execute(Principal::guest(), $this->submissionId, 10.0, null);
    }

    public function testUnknownSubmissionIsNotFound(): void
    {
        $this->grantStaff(self::GRADER, Capability::GRADE);

        $this->expectException(EntityNotFoundException::class);
        $this->gradeSubmission()->execute(Principal::user(self::GRADER), 9999, 10.0, null);
    }
}
