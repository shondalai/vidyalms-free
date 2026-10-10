<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\ListingPolicy;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Tests\Support\CountingDatabase;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * Institutional scale, proved rather than asserted: a course with ten
 * thousand enrolments answers a page from the middle of the list with a
 * bounded number of reads and without a single row outside the page
 * crossing into PHP.
 *
 * The numbers below are ceilings, not targets. What they defend is the
 * shape: a list that reads its whole filtered set fails here by two orders
 * of magnitude, whatever else it gets right.
 */
final class LargeRosterPagingTest extends TestCase
{
    private const LEARNERS = 10000;
    private const MANAGER_ID = 1;

    private TestPorts $env;
    private CountingDatabase $counting;
    private CoreKernel $kernel;
    private int $courseId = 0;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->counting = new CountingDatabase($this->env->database);

        $this->env->authorization->grant(self::MANAGER_ID, Capability::MANAGE);
        $this->env->authorization->grant(self::MANAGER_ID, Capability::VIEW_REPORTS);

        $this->kernel = new CoreKernel($this->ports());
        $this->seed();
    }

    public function testACourseRosterPagesWithoutReadingTheWholeSet(): void
    {
        $this->counting->reset();
        $this->env->users->nameLookups = 0;
        $this->env->users->accountLookups = 0;

        $page = $this->kernel->listCourseLearners->execute(
            Principal::user(self::MANAGER_ID),
            $this->courseId,
            null,
            null,
            'progress',
            'desc',
            250,
            20,
        );

        self::assertSame(self::LEARNERS, $page->total);
        self::assertCount(20, $page->items);
        self::assertLessThanOrEqual(10, $this->counting->reads, 'a roster page is a handful of reads');
        self::assertLessThanOrEqual(
            60,
            $this->counting->rowsRead,
            'a roster page hydrates its own rows and nothing else',
        );

        // CountingDatabase wraps the database port and nothing else, so the
        // cost of the host user store has to be counted where it is paid.
        self::assertSame(1, $this->env->users->nameLookups, 'one users call names the page');
        self::assertSame(
            0,
            $this->env->users->accountLookups,
            'and not one account load per row, which is what the set shaped lookup replaced',
        );
    }

    public function testTheLastPageIsTheSameCost(): void
    {
        $this->counting->reset();

        $page = $this->kernel->listCourseLearners->execute(
            Principal::user(self::MANAGER_ID),
            $this->courseId,
            null,
            null,
            'enrolled_at',
            'asc',
            (int) (self::LEARNERS / 20),
            20,
        );

        self::assertCount(20, $page->items);
        self::assertLessThanOrEqual(60, $this->counting->rowsRead, 'the last page costs what the first page costs');
    }

    public function testTheSiteWideListPagesAtTheDatabaseToo(): void
    {
        $this->counting->reset();

        $result = $this->kernel->listSiteEnrolments->execute(
            Principal::user(self::MANAGER_ID),
            null,
            null,
            null,
            null,
            'enrolled_at',
            'desc',
            17,
            25,
        );

        self::assertSame(self::LEARNERS, $result->page->total);
        self::assertCount(25, $result->page->items);
        self::assertLessThanOrEqual(80, $this->counting->rowsRead, 'the admin list pages at the database');
    }

    public function testASearchNarrowsThroughTheUsersPortAndThenAtTheDatabase(): void
    {
        $this->counting->reset();

        $page = $this->kernel->listCourseLearners->execute(
            Principal::user(self::MANAGER_ID),
            $this->courseId,
            null,
            'Learner 4242',
            'progress',
            'asc',
            1,
            20,
        );

        self::assertSame(1, $page->total);
        self::assertSame('Learner 4242', $page->items[0]->learnerName);
        self::assertLessThanOrEqual(
            20,
            $this->counting->rowsRead,
            'a search resolves to ids and then filters at the database',
        );
    }

    public function testABroadSearchKeepsExactTotalsPastTheAccountPickerLimit(): void
    {
        $pageNumber = (int) (ListingPolicy::DEFAULT_SEARCH_MATCH_LIMIT / 20) + 1;

        $page = $this->kernel->listSiteEnrolments->execute(
            Principal::user(self::MANAGER_ID),
            null,
            null,
            null,
            'Learner',
            'enrolled_at',
            'asc',
            $pageNumber,
            20,
        );

        self::assertSame(self::LEARNERS, $page->page->total);
        self::assertCount(20, $page->page->items);
        self::assertSame(
            'Learner ' . (ListingPolicy::DEFAULT_SEARCH_MATCH_LIMIT + 1),
            $page->page->items[0]->learnerName,
            'the first row beyond the capped account picker remains reachable',
        );
    }

    public function testSortingTenThousandRowsByLearnerNameIsRefusedRatherThanAnsweredSlowly(): void
    {
        try {
            $this->kernel->listCourseLearners->execute(
                Principal::user(self::MANAGER_ID),
                $this->courseId,
                null,
                null,
                'name',
                'asc',
                1,
                20,
            );

            self::fail('A name sort over ten thousand rows should be refused.');
        } catch (ValidationException $refusal) {
            self::assertArrayHasKey('sort', $refusal->errors);
            self::assertStringContainsString('Narrow the list', $refusal->errors['sort']);
            self::assertStringContainsString(
                (string) ListingPolicy::DEFAULT_NAME_SORT_CEILING,
                $refusal->errors['sort'],
                'the ceiling is in the message, so the caller knows what to aim under',
            );
        }
    }

    public function testSortingByNameUnderTheCeilingResolvesEveryNameInOneLookup(): void
    {
        $narrowed = 'Learner 12';
        $this->counting->reset();
        $this->env->users->nameLookups = 0;

        $page = $this->kernel->listCourseLearners->execute(
            Principal::user(self::MANAGER_ID),
            $this->courseId,
            null,
            $narrowed,
            'name',
            'asc',
            1,
            20,
        );

        self::assertGreaterThan(20, $page->total, 'the search still matches more rows than one page');
        self::assertLessThanOrEqual(ListingPolicy::DEFAULT_NAME_SORT_CEILING, $page->total);
        self::assertCount(20, $page->items);
        self::assertSame('Learner 12', $page->items[0]->learnerName);
        self::assertSame(1, $this->env->users->nameLookups, 'one users call names the whole sorted set');
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
     * Ten thousand learners, written straight through the port rather than
     * through the enrol use case: this test is about how the list reads, and
     * ten thousand enrolment ceremonies would take minutes to prove nothing.
     */
    private function seed(): void
    {
        $now = $this->env->clock->now()->format('Y-m-d H:i:s');

        $this->courseId = $this->env->database->insert('courses', [
            'title' => 'Scale',
            'slug' => 'scale',
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

        $this->env->database->transactional(function () use ($now): void {
            for ($index = 1; $index <= self::LEARNERS; $index++) {
                $userId = 1000 + $index;
                $this->env->users->add($userId, 'Learner ' . $index, sprintf('learner%d@example.test', $index));

                $entitlementId = $this->env->database->insert('entitlements', [
                    'user_id' => $userId,
                    'course_id' => $this->courseId,
                    'source' => 'manual',
                    'status' => 'active',
                    'granted_by' => self::MANAGER_ID,
                    'expires_at' => $index % 3 === 0 ? null : '2027-01-01 00:00:00',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->env->database->insert('enrolments', [
                    'user_id' => $userId,
                    'course_id' => $this->courseId,
                    'entitlement_id' => $entitlementId,
                    'status' => 'active',
                    'enrolled_at' => $now,
                    'progress_percent' => $index % 100,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }
}
