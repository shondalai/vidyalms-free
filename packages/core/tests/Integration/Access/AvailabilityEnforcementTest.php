<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityGrant;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Availability\DateCondition;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * Availability enforced only in the player is not enforced.
 *
 * The design names the write paths explicitly, and every one of them used to
 * ask only the structural question (is this lesson still part of the course)
 * while the outline correctly reported the item blocked with the instant it
 * opens. The consequence is not cosmetic: a blocked item with exhaustion
 * false stays in the completion denominator by design, so a learner posting
 * the raw heartbeat to a drip-locked lesson completed it, both sides of the
 * fraction counted it, the course completed early and a certificate was
 * issued for work nobody could have done.
 */
final class AvailabilityEnforcementTest extends TestCase
{
    private ManagementFixture $fixture;
    private int $enrolmentId = 0;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-04-01 00:00:00');

        $row = $this->fixture->env->database->selectOne(
            Query::from('enrolments')
                ->where('course_id', '=', $this->fixture->courseId)
                ->where('user_id', '=', ManagementFixture::LEARNER_ID),
        );

        self::assertNotNull($row);
        $this->enrolmentId = (int) $row['id'];
    }

    /**
     * The heartbeat. The learner posted completed:true to a lesson the
     * outline said opens in 2030 and got a 204 with a completed row behind
     * it.
     */
    public function testALockedLessonRefusesAHeartbeatAndRecordsNoCompletion(): void
    {
        $this->gateFrom($this->fixture->lessonId, '2030-01-01T00:00:00+00:00');

        try {
            $this->fixture->kernel->recordLessonProgress->execute(
                $this->fixture->learner(),
                $this->enrolmentId,
                $this->fixture->lessonId,
                100.0,
                null,
                null,
                true,
            );

            self::fail('A drip-locked lesson accepted a completion.');
        } catch (ConflictException $refusal) {
            // The refusal carries the same reason the outline showed, so the
            // learner is told the same thing twice rather than handed a bare
            // not-found for something the outline says opens in 2030.
            self::assertSame('availability.date.from', $refusal->extensions['reason'] ?? null);
            self::assertNotNull($refusal->extensions['opens_at'] ?? null);
        }

        self::assertNull($this->fixture->env->database->selectOne(
            Query::from('lesson_progress')
                ->where('enrolment_id', '=', $this->enrolmentId)
                ->where('lesson_id', '=', $this->fixture->lessonId),
        ));
    }

    /**
     * The same lesson, unlocked, still records: the guard is the restriction
     * and nothing else.
     */
    public function testAnUnlockedLessonStillRecords(): void
    {
        $this->gateFrom($this->fixture->lessonId, '2026-01-01T00:00:00+00:00');

        $this->fixture->kernel->recordLessonProgress->execute(
            $this->fixture->learner(),
            $this->enrolmentId,
            $this->fixture->lessonId,
            100.0,
            null,
            null,
            true,
        );

        self::assertNotNull($this->fixture->env->database->selectOne(
            Query::from('lesson_progress')
                ->where('enrolment_id', '=', $this->enrolmentId)
                ->where('lesson_id', '=', $this->fixture->lessonId),
        ));
    }

    /**
     * The attempt start. A quiz inside a drip-locked lesson passed the
     * structural reachability check and started.
     */
    public function testALockedQuizRefusesAnAttemptStart(): void
    {
        $this->gateFrom($this->fixture->quizLessonId, '2030-01-01T00:00:00+00:00');

        $this->expectException(ConflictException::class);

        $this->fixture->kernel->startAttempt->execute($this->fixture->learner(), $this->fixture->quizId);
    }

    /**
     * An administrative grant is the last word, so it opens the write path
     * too and not only the outline.
     */
    public function testAGrantOpensTheWritePath(): void
    {
        $this->gateFrom($this->fixture->lessonId, '2030-01-01T00:00:00+00:00');

        $granted = $this->fixture->call(
            'POST',
            '/manage/courses/' . $this->fixture->courseId . '/availability/grants',
            $this->fixture->teacher(),
            [
                'scope_type' => AvailabilityGrant::SCOPE_COURSE,
                'scope_id' => $this->fixture->courseId,
                'subject_type' => 'lesson',
                'subject_id' => $this->fixture->lessonId,
                'user_id' => ManagementFixture::LEARNER_ID,
                'mode' => AvailabilityGrant::MODE_GRANT,
            ],
        );

        self::assertSame(200, $granted->status, (string) json_encode($granted->data));

        $this->fixture->kernel->recordLessonProgress->execute(
            $this->fixture->learner(),
            $this->enrolmentId,
            $this->fixture->lessonId,
            50.0,
        );

        self::assertNotNull($this->fixture->env->database->selectOne(
            Query::from('lesson_progress')
                ->where('enrolment_id', '=', $this->enrolmentId)
                ->where('lesson_id', '=', $this->fixture->lessonId),
        ));
    }

    /**
     * An instructor authorized on one course wrote a permanent, invisible
     * deny against another course's lesson for any learner, and an
     * open-ended deny takes the item out of that learner's denominator, so
     * it was also a lever on whether a certificate was ever issued.
     */
    public function testAGrantAgainstAnotherCoursesSubjectIsRefused(): void
    {
        self::assertGreaterThan(0, $this->fixture->otherCourseId, 'the fixture must own a second course');

        // The other course itself is the foreign subject: a course is a
        // gated type, and naming one that is not the path course is exactly
        // the write that used to be authorized against the path course and
        // stored against the other one.
        $response = $this->fixture->call(
            'POST',
            '/manage/courses/' . $this->fixture->courseId . '/availability/grants',
            $this->fixture->teacher(),
            [
                'scope_type' => AvailabilityGrant::SCOPE_COURSE,
                'scope_id' => $this->fixture->courseId,
                'subject_type' => 'course',
                'subject_id' => $this->fixture->otherCourseId,
                'user_id' => ManagementFixture::LEARNER_ID,
                'mode' => AvailabilityGrant::MODE_DENY,
            ],
        );

        self::assertSame(422, $response->status, (string) json_encode($response->data));
        self::assertSame(0, $this->fixture->env->database->count(Query::from('availability_grants')));
    }

    /**
     * A cohort scope naming a run that does not exist, and that the learner
     * has never been a member of, was the narrowest scope and therefore the
     * last word: a phantom row beat the course grant and removed the item
     * from the denominator for good.
     */
    public function testACohortScopeNamingNoRunOfThisCourseIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        $this->fixture->kernel->manageAvailabilityGrants->create(
            $this->fixture->teacher(),
            $this->fixture->courseId,
            [
                'scope_type' => AvailabilityGrant::SCOPE_COHORT,
                'scope_id' => 987654,
                'subject_type' => 'lesson',
                'subject_id' => $this->fixture->lessonId,
                'user_id' => ManagementFixture::LEARNER_ID,
                'mode' => AvailabilityGrant::MODE_DENY,
            ],
        );
    }

    private function gateFrom(int $lessonId, string $from): void
    {
        $response = $this->fixture->call(
            'PUT',
            '/manage/lessons/' . $lessonId . '/availability',
            $this->fixture->teacher(),
            [
                'tree' => [
                    'op' => 'and',
                    'c' => [[
                        'type' => DateCondition::KEY,
                        'payload' => StoredShape::wrap(['from' => $from, 'until' => null, 'tz' => 'UTC'], 1),
                    ]],
                ],
            ],
        );

        self::assertSame(200, $response->status, (string) json_encode($response->data));
    }
}
