<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityGrant;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Availability\DateCondition;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The completion denominator, which is the one place this design's failures
 * are invisible until credentials are wrong.
 *
 * The rule, stated once: count items whose outcome is available, or blocked
 * with exhaustion false. Exclude never, and exclude blocked with exhaustion
 * true. The numerator is read from the same id set, so the percentage can
 * never exceed a hundred and a course can never become uncompletable by
 * arithmetic.
 */
final class AvailabilityDenominatorTest extends TestCase
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

    public function testABlockedItemStaysInTheDenominatorSoThePercentageDoesNotMoveWithTheCalendar(): void
    {
        $before = $this->recompute();

        $this->gate($this->fixture->quizLessonId, ['from' => '2026-05-01T00:00:00+00:00', 'until' => null]);

        $after = $this->recompute();

        // A time-like condition can only defer, and the item was already in
        // the denominator, so blocking it changes nothing.
        self::assertSame($before['lessons_total'], $after['lessons_total']);
    }

    /**
     * An instructor closing one assignment on 31 March would otherwise leave
     * every enrolment one item short of all-lessons forever, with no error
     * anywhere and the course stalled short of a hundred percent.
     */
    public function testAClosedWindowLeavesTheDenominator(): void
    {
        $before = $this->recompute();

        $this->gate($this->fixture->quizLessonId, ['from' => null, 'until' => '2026-03-01T00:00:00+00:00']);

        $after = $this->recompute();

        self::assertSame($before['lessons_total'] - 1, $after['lessons_total']);
    }

    /**
     * An open-ended denial is what excusing a learner from an item means, and
     * a denial that should not excuse them is a denial with a date on it.
     */
    public function testAnOpenEndedDenialLeavesTheDenominatorAndADatedOneDoesNot(): void
    {
        $before = $this->recompute();

        $this->deny($this->fixture->quizLessonId, null);
        self::assertSame($before['lessons_total'] - 1, $this->recompute()['lessons_total']);

        $this->deny($this->fixture->quizLessonId, '2026-06-01T00:00:00+00:00');
        self::assertSame($before['lessons_total'], $this->recompute()['lessons_total']);
    }

    /**
     * A learner who completed an item that later leaves the denominator must
     * still complete the course, and the percentage must never exceed a
     * hundred: an unscoped numerator against a scoped denominator would
     * compare more completed items than the course contains.
     */
    public function testTheNumeratorMovesWithTheDenominator(): void
    {
        $this->complete($this->fixture->lessonId);
        $this->complete($this->fixture->quizLessonId);
        $this->complete($this->fixture->assignmentLessonId);

        $all = $this->recompute();
        self::assertSame($all['lessons_total'], $all['lessons_completed']);
        self::assertLessThanOrEqual(100.0, (float) $all['percent']);

        // The item the learner finished now drops out of the denominator.
        $this->gate($this->fixture->quizLessonId, ['from' => null, 'until' => '2026-03-01T00:00:00+00:00']);
        $after = $this->recompute();

        self::assertSame($after['lessons_total'], $after['lessons_completed']);
        self::assertLessThanOrEqual(100.0, (float) $after['percent']);
    }

    /**
     * @param array<string, mixed> $window
     */
    private function gate(int $lessonId, array $window): void
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
                        'payload' => StoredShape::wrap($window + ['tz' => 'UTC'], 1),
                    ]],
                ],
            ],
        );

        self::assertSame(200, $response->status);
    }

    private function deny(int $lessonId, ?string $expiresAt): void
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/courses/' . $this->fixture->courseId . '/availability/grants',
            $this->fixture->teacher(),
            [
                'scope_type' => AvailabilityGrant::SCOPE_COURSE,
                'scope_id' => $this->fixture->courseId,
                'subject_type' => 'lesson',
                'subject_id' => $lessonId,
                'user_id' => ManagementFixture::LEARNER_ID,
                'mode' => AvailabilityGrant::MODE_DENY,
                'expires_at' => $expiresAt,
            ],
        );

        self::assertSame(200, $response->status);
    }

    /**
     * Arranged through the port rather than the player, because a quiz lesson
     * completes through an attempt and an assignment lesson through a grade,
     * and what this test is about is the arithmetic on the two sides of the
     * fraction rather than the three roads to a completed row.
     */
    private function complete(int $lessonId): void
    {
        $now = '2026-04-01 00:00:00';

        $this->fixture->env->database->insert('lesson_progress', [
            'enrolment_id' => $this->enrolmentId,
            'lesson_id' => $lessonId,
            'user_id' => ManagementFixture::LEARNER_ID,
            'status' => 'completed',
            'progress_percent' => 100,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function recompute(): array
    {
        $this->fixture->kernel->recomputeCourseProgress->execute($this->fixture->manager(), $this->enrolmentId);

        $row = $this->fixture->env->database->selectOne(
            Query::from('course_progress')->where('enrolment_id', '=', $this->enrolmentId),
        );

        self::assertNotNull($row);

        return $row;
    }
}
