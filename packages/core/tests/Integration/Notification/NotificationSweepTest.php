<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationRule;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\EntitlementExpiringSweep;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\NotificationSweepTask;
use Shondalai\VidyaLMS\Core\Registry\Context\SweepContext;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * A sweep run twice in one period produces exactly one queue row.
 *
 * The dedupe digest works elsewhere only because every send has an event
 * behind it; a sweep has none, so it mints a synthetic reference and carries
 * a period bucket. A cron that runs twice would otherwise send twice, which
 * is a defect shipped elsewhere with one-time-only switched on.
 */
final class NotificationSweepTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-04-01 10:00:00');
    }

    public function testTheSweepRuleIsSeededDisabled(): void
    {
        // Seeding happens on the first enqueue, which is what the first
        // dispatched event triggers; ask for it directly here.
        $this->fixture->kernel->ensureDefaultNotifications->execute();

        $rule = $this->fixture->kernel->notificationRules->findByKey(EntitlementExpiringSweep::KEY);

        self::assertNotNull($rule);
        self::assertSame(NotificationRule::STATE_DISABLED, $rule->state);
    }

    public function testRunningTheSweepTwiceInOnePeriodWritesOneRow(): void
    {
        $this->enableSweep();
        $this->expiringEntitlement();

        $this->runSweep();
        $first = $this->intentCount();

        $this->runSweep();
        $second = $this->intentCount();

        self::assertSame(1, $first);
        self::assertSame($first, $second);
    }

    public function testTheNextPeriodWritesAgain(): void
    {
        $this->enableSweep();
        $this->expiringEntitlement();

        $this->runSweep('2026-04-01');
        $this->runSweep('2026-04-02');

        self::assertSame(2, $this->intentCount());
    }

    /**
     * The shard keeps one run bounded, and a learner stays in the same bin
     * between runs, so a bounded run is still a complete run across the set.
     */
    public function testTheBinnedRunOnlyOwnsItsOwnShard(): void
    {
        $this->enableSweep();
        $entitlementId = $this->expiringEntitlement();

        $binCount = 4;
        $mine = $entitlementId % $binCount;
        $other = ($mine + 1) % $binCount;

        $this->runSweep('2026-04-01', $other, $binCount);
        self::assertSame(0, $this->intentCount());

        $this->runSweep('2026-04-01', $mine, $binCount);
        self::assertSame(1, $this->intentCount());
    }

    private function enableSweep(): void
    {
        $this->fixture->kernel->notificationRules->save(new NotificationRule(
            null,
            EntitlementExpiringSweep::KEY,
            EntitlementExpiringSweep::KEY,
            'learner',
            'account',
            EntitlementExpiringSweep::KEY,
            ['email'],
            state: NotificationRule::STATE_ENABLED,
        ));
    }

    private function expiringEntitlement(): int
    {
        $row = $this->fixture->env->database->selectOne(
            Query::from('entitlements')
                ->where('course_id', '=', $this->fixture->courseId)
                ->where('user_id', '=', ManagementFixture::LEARNER_ID),
        );

        self::assertNotNull($row);

        $this->fixture->env->database->update(
            'entitlements',
            ['expires_at' => '2026-04-08 10:00:00'],
            ['id' => (int) $row['id']],
        );

        return (int) $row['id'];
    }

    /**
     * One bin by default, so a test about the period bucket is not also a
     * test about which shard a row happens to land in.
     */
    private function runSweep(string $bucket = '2026-04-01', int $binIndex = 0, int $binCount = 1): void
    {
        $summary = $this->fixture->kernel->runScheduledTask(
            NotificationSweepTask::TYPE,
            1,
            ['binIndex' => $binIndex, 'binCount' => $binCount, 'periodBucket' => $bucket],
            $this->fixture->manager(),
        );

        self::assertNotNull($summary);
    }

    private function intentCount(): int
    {
        return $this->fixture->env->database->count(
            Query::from('notification_queue')->where('trigger_key', '=', EntitlementExpiringSweep::KEY),
        );
    }
}
