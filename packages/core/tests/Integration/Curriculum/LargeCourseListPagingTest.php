<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Curriculum;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ManagedCourse;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Tests\Support\CountingDatabase;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The managed course list sorts on three figures that are computed on read,
 * and computing them on read used to mean reading every course and every
 * enrolment on the site. It does not any more: the counts are grouped
 * aggregates over a join, so the sort and the page happen in one query.
 */
final class LargeCourseListPagingTest extends TestCase
{
    private const COURSES = 2000;
    private const MANAGER_ID = 1;

    private TestPorts $env;
    private CountingDatabase $counting;
    private CoreKernel $kernel;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->counting = new CountingDatabase($this->env->database);
        $this->env->authorization->grant(self::MANAGER_ID, Capability::MANAGE);
        $this->env->users->add(self::MANAGER_ID, 'Mo Manager');

        $this->kernel = new CoreKernel($this->ports());
        $this->seed();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function countSorts(): array
    {
        return [
            'enrolled_count' => ['enrolled_count'],
            'active_count' => ['active_count'],
            'completion_rate' => ['completion_rate'],
        ];
    }

    /**
     * @dataProvider countSorts
     */
    public function testSortingOnAReadCountPagesAtTheDatabase(string $sort): void
    {
        $this->counting->reset();

        $page = $this->kernel->listManagedCourses->execute(
            Principal::user(self::MANAGER_ID),
            null,
            null,
            $sort,
            'desc',
            40,
            20,
        );

        self::assertSame(self::COURSES, $page->total);
        self::assertCount(20, $page->items);
        self::assertLessThanOrEqual(8, $this->counting->reads, 'a page of the course list is a handful of reads');
        self::assertLessThanOrEqual(
            80,
            $this->counting->rowsRead,
            'sorting on a count reads the page, not the site',
        );
    }

    public function testTheCountSortOrdersByTheCountAndNotByTheId(): void
    {
        $page = $this->kernel->listManagedCourses->execute(
            Principal::user(self::MANAGER_ID),
            null,
            null,
            'enrolled_count',
            'desc',
            1,
            5,
        );

        $counts = array_map(
            static fn (ManagedCourse $item): int => $item->counts->enrolled,
            $page->items,
        );

        self::assertSame([3, 3, 3, 3, 3], $counts, 'the busiest courses come first');

        $ascending = $this->kernel->listManagedCourses->execute(
            Principal::user(self::MANAGER_ID),
            null,
            null,
            'enrolled_count',
            'asc',
            1,
            5,
        );

        self::assertSame(
            [0, 0, 0, 0, 0],
            array_map(static fn (ManagedCourse $item): int => $item->counts->enrolled, $ascending->items),
            'the emptiest courses come first ascending',
        );
    }

    public function testACourseWithNoEnrolmentsHasNoCompletionRate(): void
    {
        $page = $this->kernel->listManagedCourses->execute(
            Principal::user(self::MANAGER_ID),
            null,
            null,
            'completion_rate',
            'desc',
            (int) (self::COURSES / 20),
            20,
        );

        $rates = array_map(
            static fn (ManagedCourse $item): ?float => $item->counts->completionRate(),
            $page->items,
        );

        self::assertSame([null], array_values(array_unique($rates)), 'the last page is the courses with no rate');
    }

    public function testCategoryFilterAppliesBeforeBothStoredAndAggregateSortingAndPagination(): void
    {
        foreach (['title', 'enrolled_count', 'completion_rate'] as $sort) {
            $this->counting->reset();
            $page = $this->kernel->listManagedCourses->execute(
                Principal::user(self::MANAGER_ID), null, null, $sort, 'desc', 2, 20, categoryId: 7,
            );
            self::assertSame(666, $page->total);
            self::assertCount(20, $page->items);
            foreach ($page->items as $item) {
                self::assertSame(7, $item->course->categoryId);
            }
            self::assertLessThanOrEqual(80, $this->counting->rowsRead);
        }
    }

    private function ports(): Ports
    {
        return new Ports(
            database: $this->counting,
            users: $this->env->users,
            authorization: $this->env->authorization,
            storage: $this->env->storage,
            mailer: $this->env->mailer,
            scheduler: $this->env->scheduler,
            eventBridge: $this->env->eventBridge,
            commerce: $this->env->commerce,
            translator: $this->env->translator,
            cache: $this->env->cache,
            clock: $this->env->clock,
            random: $this->env->random,
            logger: new NullLogger(),
        );
    }

    /**
     * Every fourth course carries three enrolments, one of them completed;
     * the rest carry none. That makes "sorted by count" and "sorted by id"
     * different answers, which is the only way the assertion means anything.
     */
    private function seed(): void
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');
        $userId = 5000;

        $this->env->database->transactional(function () use ($now, &$userId): void {
            for ($index = 1; $index <= self::COURSES; $index++) {
                $courseId = $this->env->database->insert('courses', [
                    'title' => 'Course ' . $index,
                    'category_id' => $index % 3 === 0 ? 7 : 8,
                    'slug' => 'course-' . $index,
                    'visibility' => 'public',
                    'state' => 'published',
                    'price_mode' => 'free',
                    'completion_rule' => 'all_lessons',
                    'featured' => 0,
                    'created_by' => self::MANAGER_ID,
                    'published_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($index % 4 !== 0) {
                    continue;
                }

                foreach (['active', 'active', 'completed', 'cancelled'] as $status) {
                    ++$userId;

                    $entitlementId = $this->env->database->insert('entitlements', [
                        'user_id' => $userId,
                        'course_id' => $courseId,
                        'source' => 'manual',
                        'status' => 'active',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $this->env->database->insert('enrolments', [
                        'user_id' => $userId,
                        'course_id' => $courseId,
                        'entitlement_id' => $entitlementId,
                        'status' => $status,
                        'enrolled_at' => $now,
                        'progress_percent' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }
        });
    }
}
