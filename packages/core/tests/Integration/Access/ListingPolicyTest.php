<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\ListingPolicy;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The list ceilings are a site's decision, not the product's.
 *
 * They used to be constants on the use cases that enforced them, so a
 * university with more enrolments than the name sort ceiling could not use
 * the only name sort in the product without forking a core file, and a small
 * site could not lower a page size to protect a shared host.
 */
final class ListingPolicyTest extends TestCase
{
    private const MANAGER_ID = 1;

    private TestPorts $env;

    private int $courseId = 0;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::MANAGER_ID, Capability::MANAGE);
        $this->env->authorization->grant(self::MANAGER_ID, Capability::VIEW_REPORTS);
    }

    public function testAHostThatSaysNothingGetsTheShippedNumbers(): void
    {
        $kernel = new CoreKernel($this->env->ports);

        self::assertSame(ListingPolicy::DEFAULT_MAX_PER_PAGE, $kernel->listingPolicy->maxPerPage);
        self::assertSame(ListingPolicy::DEFAULT_NAME_SORT_CEILING, $kernel->listingPolicy->nameSortCeiling);
        self::assertSame(ListingPolicy::DEFAULT_SEARCH_MATCH_LIMIT, $kernel->listingPolicy->searchMatchLimit);
    }

    public function testAMalformedSettingNeverTakesTheApiDown(): void
    {
        $kernel = new CoreKernel($this->env->ports, ['listing' => ['max_per_page' => 'lots']]);

        self::assertSame(ListingPolicy::DEFAULT_MAX_PER_PAGE, $kernel->listingPolicy->maxPerPage);
    }

    public function testASiteCanLowerThePageCeiling(): void
    {
        $kernel = new CoreKernel($this->env->ports, ['listing' => ['max_per_page' => 5]]);
        $this->seed();

        $accepted = $kernel->listCourseLearners->execute(
            Principal::user(self::MANAGER_ID),
            $this->courseId,
            null,
            null,
            'enrolled_at',
            'asc',
            1,
            5,
        );

        self::assertCount(2, $accepted->items);

        try {
            $kernel->listCourseLearners->execute(
                Principal::user(self::MANAGER_ID),
                $this->courseId,
                null,
                null,
                'enrolled_at',
                'asc',
                1,
                20,
            );

            self::fail('a page of twenty is over a ceiling of five');
        } catch (ValidationException $refusal) {
            self::assertSame('Must be between 1 and 5.', $refusal->errors['per_page']);
        }
    }

    public function testASiteCanRaiseTheNameSortCeiling(): void
    {
        $refusing = new CoreKernel($this->env->ports, ['listing' => ['name_sort_ceiling' => 1]]);
        $this->seed();

        try {
            $refusing->listCourseLearners->execute(
                Principal::user(self::MANAGER_ID),
                $this->courseId,
                null,
                null,
                'name',
                'asc',
                1,
                20,
            );

            self::fail('two rows over a ceiling of one is a refusal');
        } catch (ValidationException $refusal) {
            self::assertStringContainsString('refused above 1', $refusal->errors['sort']);
        }

        $allowing = new CoreKernel($this->env->ports, ['listing' => ['name_sort_ceiling' => 10]]);

        $page = $allowing->listCourseLearners->execute(
            Principal::user(self::MANAGER_ID),
            $this->courseId,
            null,
            null,
            'name',
            'asc',
            1,
            20,
        );

        self::assertCount(2, $page->items, 'the same rows the same site refused a moment ago');
    }

    private function seed(): void
    {
        if ($this->courseId !== 0) {
            return;
        }

        $now = $this->env->clock->now()->format('Y-m-d H:i:s');

        $this->courseId = $this->env->database->insert('courses', [
            'title' => 'Scaled',
            'slug' => 'scaled',
            'state' => 'published',
            'visibility' => 'public',
            'price_mode' => 'free',
            'completion_rule' => 'all_lessons',
            'created_by' => self::MANAGER_ID,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([[701, 'Ada Lovelace'], [702, 'Bea Arthur']] as [$userId, $name]) {
            $this->env->users->add($userId, $name, sprintf('user%d@example.test', $userId));

            $entitlementId = $this->env->database->insert('entitlements', [
                'user_id' => $userId,
                'course_id' => $this->courseId,
                'source' => 'manual',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->env->database->insert('enrolments', [
                'user_id' => $userId,
                'course_id' => $this->courseId,
                'entitlement_id' => $entitlementId,
                'status' => 'active',
                'enrolled_at' => $now,
                'progress_percent' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
