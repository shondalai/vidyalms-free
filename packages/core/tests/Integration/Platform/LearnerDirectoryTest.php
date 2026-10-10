<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

final class LearnerDirectoryTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testAStableTieBreakKeepsPageBoundariesExact(): void
    {
        $this->seedLearners(100, 24, 'Boundary Learner');
        $this->fixture->env->users->accountLookups = 0;
        $this->fixture->env->users->accountSetLookups = 0;

        $response = $this->fixture->call('GET', '/admin/learners', $this->fixture->manager(), [], [
            'q' => 'Boundary',
            'sort' => 'progress',
            'order' => 'desc',
            'page' => '2',
            'per_page' => '10',
        ]);

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(24, $response->data['total']);
        self::assertSame(2, $response->data['page']);
        self::assertSame(10, $response->data['per_page']);
        self::assertSame(
            range(110, 119),
            array_column($response->data['items'], 'user_id'),
            'equal aggregate values are finally ordered by user id ascending',
        );
        self::assertSame(1, $this->fixture->env->users->accountSetLookups);
        self::assertSame(0, $this->fixture->env->users->accountLookups);
    }

    public function testSearchIsNotSilentlyCutAtTheAccountPickerLimit(): void
    {
        $this->seedLearners(1000, 505, 'Scale Learner');

        $response = $this->fixture->call('GET', '/admin/learners', $this->fixture->manager(), [], [
            'q' => 'Scale Learner',
            'sort' => 'enrolled_at',
            'order' => 'asc',
            'page' => '26',
            'per_page' => '20',
        ]);

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(505, $response->data['total']);
        self::assertCount(5, $response->data['items']);
        self::assertSame(range(1500, 1504), array_column($response->data['items'], 'user_id'));
    }

    public function testGuestAndNonManagerCannotReadTheDirectory(): void
    {
        $staffId = 78;
        $this->fixture->env->authorization->grant($staffId, Capability::TEACH);

        self::assertSame(
            401,
            $this->fixture->call('GET', '/admin/learners', Principal::guest())->status,
        );
        self::assertSame(
            403,
            $this->fixture->call('GET', '/admin/learners', Principal::user($staffId))->status,
        );
    }

    public function testInvalidPagingAndSortAreRejected(): void
    {
        self::assertSame(
            422,
            $this->fixture->call('GET', '/admin/learners', $this->fixture->manager(), [], ['page' => '0'])->status,
        );
        self::assertSame(
            422,
            $this->fixture->call('GET', '/admin/learners', $this->fixture->manager(), [], ['sort' => 'name'])->status,
        );
    }

    private function seedLearners(int $firstId, int $count, string $namePrefix): void
    {
        $now = $this->fixture->env->clock->now()->format('Y-m-d H:i:s');

        $this->fixture->env->database->transactional(function () use ($firstId, $count, $namePrefix, $now): void {
            for ($index = 0; $index < $count; ++$index) {
                $userId = $firstId + $index;
                $this->fixture->env->users->add(
                    $userId,
                    sprintf('%s %04d', $namePrefix, $index + 1),
                    sprintf('directory%d@example.test', $userId),
                );

                $entitlementId = $this->fixture->env->database->insert('entitlements', [
                    'user_id' => $userId,
                    'course_id' => $this->fixture->courseId,
                    'source' => 'manual',
                    'status' => 'active',
                    'granted_by' => ManagementFixture::MANAGER_ID,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $this->fixture->env->database->insert('enrolments', [
                    'user_id' => $userId,
                    'course_id' => $this->fixture->courseId,
                    'entitlement_id' => $entitlementId,
                    'status' => 'active',
                    'enrolled_at' => $now,
                    // Equal values are intentional: the test exercises the
                    // stable user-id tie break across a page boundary.
                    'progress_percent' => 50,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }
}
