<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assignments;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\HalfMarksPolicy;

/**
 * The late policy registry, reached by a free code path.
 *
 * apply() had no call site anywhere, so the free "none" provider and all four
 * paid ones were classes nothing drove: a free provider a free code path
 * cannot reach is the failure one step in from an empty seam. The free
 * columns on the course exist precisely so a free self-paced course with a
 * due date on an assignment exercises it.
 */
final class LatePolicyReachTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-04-10 10:00:00');
    }

    /**
     * The free policy marks late and changes nothing, so the free build's
     * grade is what it always was and the seam is still driven.
     */
    public function testTheFreeNonePolicyLeavesTheGradeAlone(): void
    {
        $submissionId = $this->submit();

        $graded = $this->fixture->kernel->gradeSubmission->execute(
            $this->fixture->teacher(),
            $submissionId,
            8.0,
            null,
        );

        self::assertSame(8.0, $graded->pointsAwarded());
    }

    /**
     * A registered policy really does reach the grade write, with no line of
     * its rule in the grading path.
     */
    public function testARegisteredPolicyAdjustsTheGrade(): void
    {
        $this->fixture->kernel->registries->latePolicies->register(new HalfMarksPolicy());

        $this->fixture->env->database->update(
            'courses',
            ['late_policy_key' => HalfMarksPolicy::KEY],
            ['id' => $this->fixture->courseId],
        );

        $submissionId = $this->submit();

        $graded = $this->fixture->kernel->gradeSubmission->execute(
            $this->fixture->teacher(),
            $submissionId,
            8.0,
            null,
        );

        self::assertSame(4.0, $graded->pointsAwarded());
    }

    /**
     * The published miss: a key whose package is gone falls back to none and
     * records the substitution rather than throwing out of the grade write.
     */
    public function testAnUnregisteredKeyFallsBackToNoneAndRecordsIt(): void
    {
        $this->fixture->env->database->update(
            'courses',
            ['late_policy_key' => 'acme.gone'],
            ['id' => $this->fixture->courseId],
        );

        $outcome = $this->fixture->kernel->applyLatePolicy->forCourse(
            $this->fixture->courseId,
            80.0,
            new \DateTimeImmutable('2026-04-01 00:00:00'),
            new \DateTimeImmutable('2026-04-10 00:00:00'),
        );

        self::assertSame(80.0, $outcome->percentage);
        self::assertTrue($outcome->wasSubstituted());
        self::assertSame('acme.gone', $outcome->substitutedFor);
    }

    private function submit(): int
    {
        $now = '2026-04-10 09:00:00';

        $assignment = $this->fixture->env->database->selectOne(
            Query::from('assignments')->where('lesson_id', '=', $this->fixture->assignmentLessonId),
        );

        self::assertNotNull($assignment);

        $enrolment = $this->fixture->env->database->selectOne(
            Query::from('enrolments')
                ->where('course_id', '=', $this->fixture->courseId)
                ->where('user_id', '=', ManagementFixture::LEARNER_ID),
        );

        self::assertNotNull($enrolment);

        return $this->fixture->env->database->insert('submissions', [
            'assignment_id' => (int) $assignment['id'],
            'enrolment_id' => (int) $enrolment['id'],
            'user_id' => ManagementFixture::LEARNER_ID,
            'attempt_number' => 1,
            'status' => 'submitted',
            'text_response' => 'done',
            'submitted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
