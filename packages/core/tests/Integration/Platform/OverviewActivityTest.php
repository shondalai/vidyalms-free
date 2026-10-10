<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

final class OverviewActivityTest extends TestCase
{
    public function testInstructorsCannotReadSiteWideCounts(): void
    {
        $fixture = ManagementFixture::create();
        self::assertSame(403, $fixture->call('GET', '/admin/dashboard', $fixture->teacher())->status);
    }

    public function testPeriodsUseUtcBoundariesZeroFillAndComparablePartialDays(): void
    {
        $fixture = ManagementFixture::create();
        $db = $fixture->env->database;
        $source = $db->selectOne(Query::from('enrolments'));
        self::assertNotNull($source);
        $db->update('enrolments', ['enrolled_at' => '2025-01-01 00:00:00'], ['id' => $source['id']]);
        unset($source['id']);
        // Frozen clock: Jan 15, 10:00 UTC. Previous window ends Jan 8, 10:00.
        $dates = ['2026-01-02 00:00:00', '2026-01-08 09:59:59', '2026-01-08 10:00:01', '2026-01-09 00:00:00', '2026-01-15 10:00:00', '2026-01-15 10:00:01'];
        foreach ($dates as $index => $date) {
            $db->insert('enrolments', array_replace($source, [
                'user_id' => 200 + $index,
                'enrolled_at' => $date,
                'completed_at' => $date,
                'status' => 'completed',
            ]));
        }
        $response = $fixture->call('GET', '/admin/dashboard', $fixture->manager(), [], ['period_days' => '7']);
        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        $activity = $response->data['activity'];
        self::assertCount(7, $activity['daily']);
        self::assertSame('2026-01-09', $activity['daily'][0]['date']);
        self::assertSame(1, $activity['daily'][0]['enrolments']);
        self::assertSame(0, $activity['daily'][1]['enrolments']);
        self::assertSame(2, array_sum(array_column($activity['daily'], 'enrolments')));
        self::assertSame(2, array_sum(array_column($activity['daily'], 'previous_enrolments')));
        self::assertSame(2, array_sum(array_column($activity['daily'], 'completions')));
        self::assertSame('2026-01-15 10:00:00', $activity['recent'][0]['occurred_at']);
        self::assertCount(4, $activity['recent']);
    }

    public function testRecentEventsAreBoundedAndInvalidPeriodsAreRefused(): void
    {
        $fixture = ManagementFixture::create();
        $source = $fixture->env->database->selectOne(Query::from('enrolments'));
        self::assertNotNull($source);
        $fixture->env->database->update('enrolments', ['enrolled_at' => '2025-01-01 00:00:00'], ['id' => $source['id']]);
        unset($source['id']);
        for ($index = 0; $index < 15; ++$index) {
            $fixture->env->database->insert('enrolments', array_replace($source, [
                'user_id' => 300 + $index,
                'enrolled_at' => sprintf('2026-01-15 09:%02d:00', $index),
            ]));
        }
        $response = $fixture->call('GET', '/admin/dashboard', $fixture->manager());
        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertCount(30, $response->data['activity']['daily']);
        self::assertCount(6, $response->data['activity']['recent']);
        self::assertSame('2026-01-15 09:14:00', $response->data['activity']['recent'][0]['occurred_at']);
        self::assertSame(422, $fixture->call('GET', '/admin/dashboard', $fixture->manager(), [], ['period_days' => '365'])->status);
    }

    public function testCourseRatesExcludeCancelledEnrolmentsAndRankAtTheDatabase(): void
    {
        $fixture = ManagementFixture::create();
        $db = $fixture->env->database;
        $source = $db->selectOne(Query::from('enrolments'));
        self::assertNotNull($source);
        unset($source['id']);
        foreach (['completed', 'suspended', 'cancelled'] as $index => $status) {
            $db->insert('enrolments', array_replace($source, ['user_id' => 400 + $index, 'status' => $status]));
        }
        $response = $fixture->call('GET', '/admin/dashboard', $fixture->manager());
        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        $course = $response->data['top_courses'][0];
        self::assertSame(1, $course['active_count']);
        self::assertSame(3, $course['enrolled_count']);
        self::assertSame(1, $course['completed_count']);
        self::assertSame(3, $course['lesson_count']);
    }
}
