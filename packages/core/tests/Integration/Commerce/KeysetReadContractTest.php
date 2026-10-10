<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Commerce;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\RetentionSettings;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Domain\Reporting\KeysetCursor;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Progress\SqlActivityLogReader;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Progress\SqlLessonProgressRepository;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The property the whole keyset contract exists for: an export running while
 * rows are inserted produces no duplicate and no gap.
 *
 * Asserted by inserting during the walk rather than by inspecting SQL,
 * because the failure offset paging produces is invisible in a query plan and
 * obvious in a result set.
 */
final class KeysetReadContractTest extends TestCase
{
    public function testAnActivityLogExportRunningWhileHeartbeatsInsertHasNoDuplicateAndNoGap(): void
    {
        $env = TestPorts::create();
        $reader = new SqlActivityLogReader($env->database);

        // Twelve rows sharing four instants, which is what a heartbeat-fed
        // table actually looks like and the exact shape a cursor on the
        // instant alone repeats or skips.
        for ($i = 1; $i <= 12; $i++) {
            $this->activity($env, 700 + $i, '2026-03-0' . (int) ceil($i / 3) . ' 09:00:00');
        }

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $page = $reader->page($cursor, 5);

            foreach ($page['rows'] as $row) {
                $seen[] = (int) $row['id'];
            }

            $cursor = $page['next'];
            $pages++;

            // A concurrent heartbeat lands mid-export, at an instant already
            // served. An offset cursor would shift every later row by one and
            // skip exactly one of them.
            if ($pages === 1) {
                $this->activity($env, 799, '2026-03-01 09:00:00');
            }
        } while ($cursor !== null && $pages < 10);

        self::assertNull($cursor, 'the walk did not reach the end');
        self::assertSame($seen, array_unique($seen), 'the export served a row twice');
        self::assertCount(12, $seen, 'the export skipped a row inserted behind the cursor');
    }

    public function testTheCursorSurvivesEncodingAndAnUnreadableOneStartsOver(): void
    {
        $cursor = KeysetCursor::of(['2026-03-01 09:00:00', 42]);
        $decoded = KeysetCursor::decode($cursor->encode());

        self::assertNotNull($decoded);
        self::assertSame(['2026-03-01 09:00:00', 42], $decoded->parts);
        // URL-safe, so it survives a query string without escaping.
        self::assertDoesNotMatchRegularExpression('/[+\/=]/', $cursor->encode());

        // A stale or truncated token from an old client starts the export
        // again rather than failing it.
        self::assertNull(KeysetCursor::decode('not base64 at all !!'));
        self::assertNull(KeysetCursor::decode(''));
        self::assertNull(KeysetCursor::decode(null));
    }

    public function testAScopeTheReaderCannotExpressNarrowsToNothingRatherThanEverything(): void
    {
        $env = TestPorts::create();
        $reader = new SqlActivityLogReader($env->database);
        $this->activity($env, 801, '2026-03-01 09:00:00', 55);
        $this->activity($env, 802, '2026-03-01 09:00:01', 56);

        self::assertCount(1, $reader->page(null, 10, VisibilityScope::forUser(801))['rows']);
        self::assertCount(1, $reader->page(null, 10, VisibilityScope::forCourse(55))['rows']);
        self::assertCount(2, $reader->page(null, 10, VisibilityScope::site())['rows']);

        // The direction that matters: an unrecognised scope type hands back
        // nothing, because one that answered site-wide would hand one
        // customer's rows to another the day a new type is added.
        self::assertCount(0, $reader->page(null, 10, VisibilityScope::of('cohort', 9))['rows']);
    }

    public function testLessonProgressPagesOnTheEnrolmentAndIdPairWithoutSkippingABusyEnrolment(): void
    {
        $env = TestPorts::create();
        $repository = new SqlLessonProgressRepository($env->database, $env->clock);
        $now = $env->clock->now()->format('Y-m-d H:i:s');

        // Two enrolments, one of them busy: paging on enrolment_id alone
        // would serve one row of enrolment 1 and jump straight to enrolment 2.
        foreach ([[1, 10], [1, 11], [1, 12], [2, 10]] as [$enrolmentId, $lessonId]) {
            $env->database->insert('lesson_progress', [
                'enrolment_id' => $enrolmentId,
                'lesson_id' => $lessonId,
                'user_id' => 900 + $enrolmentId,
                'status' => 'in_progress',
                'progress_percent' => 10,
                'seconds_spent' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $seen = [];
        $cursor = null;

        do {
            $page = $repository->pageByKeyset($cursor, 2);

            foreach ($page['rows'] as $row) {
                $seen[] = $row->enrolmentId . ':' . $row->lessonId;
            }

            $cursor = $page['next'];
        } while ($cursor !== null);

        self::assertSame(['1:10', '1:11', '1:12', '2:10'], $seen);
    }

    /**
     * The cursor carries no authority, so the scope has to.
     *
     * pageByKeyset took no scope at all: a supplied cursor chose which
     * enrolment a page started at and nothing narrowed the page after that, so
     * the walk was the whole table. Its cursor is base64 of a JSON array, so a
     * client that composed one would have chosen where to start.
     */
    public function testTheLessonProgressWalkIsNarrowedByTheScopeAndNotByTheCursor(): void
    {
        $env = TestPorts::create();
        $repository = new SqlLessonProgressRepository($env->database, $env->clock);
        $now = $env->clock->now()->format('Y-m-d H:i:s');

        foreach ([[1, 10, 901], [2, 10, 902], [3, 10, 903]] as [$enrolmentId, $lessonId, $userId]) {
            $env->database->insert('lesson_progress', [
                'enrolment_id' => $enrolmentId,
                'lesson_id' => $lessonId,
                'user_id' => $userId,
                'status' => 'in_progress',
                'progress_percent' => 10,
                'seconds_spent' => 30,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        self::assertCount(3, $repository->pageByKeyset(null, 10)['rows']);
        self::assertCount(3, $repository->pageByKeyset(null, 10, VisibilityScope::site())['rows']);

        $mine = $repository->pageByKeyset(null, 10, VisibilityScope::forUser(902))['rows'];
        self::assertCount(1, $mine);
        self::assertSame(2, $mine[0]->enrolmentId);

        // A scope kind this reader cannot honour narrows to nothing rather
        // than being ignored, which is the same fail-closed direction the
        // activity log reader takes.
        self::assertCount(0, $repository->pageByKeyset(null, 10, VisibilityScope::of('cohort', 9))['rows']);
    }

    public function testTheTwoRetentionSettingsAreRowsWithClampedDefaults(): void
    {
        $env = TestPorts::create();
        $settings = new Settings(new DatabaseSettingsRepository($env->database, $env->clock));
        $retention = new RetentionSettings($settings);

        self::assertSame(RetentionSettings::DEFAULT_ACTIVITY_LOG_DAYS, $retention->activityLogDays());
        self::assertSame(RetentionSettings::DEFAULT_REPORT_ARTEFACT_DAYS, $retention->reportArtefactDays());
        self::assertTrue($retention->activityLogCoversDisputes());

        // The floor exists so a misconfiguration cannot make a sweep delete
        // on sight, and the ceiling so "forever" has to be said out loud.
        $settings->set(RetentionSettings::ACTIVITY_LOG_DAYS, 0);
        self::assertSame(1, $retention->activityLogDays());
        self::assertFalse($retention->activityLogCoversDisputes());

        $settings->set(RetentionSettings::ACTIVITY_LOG_DAYS, 999999);
        self::assertSame(3650, $retention->activityLogDays());

        // The expiry a report run is stamped with, computed once here so a
        // caller that forgets it cannot insert a null the sweep skips forever.
        $now = new \DateTimeImmutable('2026-03-01 00:00:00', new \DateTimeZone('UTC'));
        self::assertSame('2026-03-31', $retention->reportArtefactExpiry($now)->format('Y-m-d'));
    }

    private function activity(TestPorts $env, int $userId, string $occurredAt, ?int $courseId = null): void
    {
        $env->database->insert('activity_log', [
            'user_id' => $userId,
            'course_id' => $courseId,
            'action' => 'lesson.viewed',
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ]);
    }
}
