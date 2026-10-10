<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\RetentionSettings;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\PersonalLogRetentionTask;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class PersonalLogRetentionTest extends TestCase
{
    public function testOnlyExpiredHistoryAndTerminalDeliveriesAreRemoved(): void
    {
        $env = TestPorts::create();
        $db = $env->database;
        $now = $env->clock->now()->format('Y-m-d H:i:s');
        $old = $env->clock->now()->modify('-401 days')->format('Y-m-d H:i:s');
        foreach ([$old, $now] as $stamp) {
            $db->insert('activity_log', ['user_id' => 7, 'action' => 'view', 'occurred_at' => $stamp,
                'created_at' => $stamp, 'updated_at' => $stamp]);
            $db->insert('email_log', ['recipient' => 'learner@example.test', 'template_key' => 'test', 'status' => 'sent',
                'created_at' => $stamp, 'updated_at' => $stamp]);
            foreach (['sent', 'pending'] as $state) {
                $queueId = $db->insert('notification_queue', ['trigger_key' => 'test', 'category_key' => 'test', 'template_key' => 'test',
                    'event_ref' => $stamp . $state, 'occurrence_key' => 'once', 'dedupe_key' => hash('sha256', $stamp . $state),
                    'run_at' => $stamp, 'state' => $state, 'created_at' => $stamp, 'updated_at' => $stamp]);
                $db->insert('org_notification_secrets', ['queue_id' => $queueId, 'organisation_id' => 1,
                    'invitation_id' => 1, 'sealed_payload' => 'encrypted', 'expires_at' => $stamp,
                    'created_at' => $stamp, 'updated_at' => $stamp]);
            }
        }
        $task = new PersonalLogRetentionTask($db, $env->clock,
            new RetentionSettings(new Settings(new DatabaseSettingsRepository($db, $env->clock))));
        self::assertSame('3', $task->run(new ScheduledTaskRun(1, [], Principal::guest())));
        self::assertSame(1, $db->count(Query::from('activity_log')));
        self::assertSame(1, $db->count(Query::from('email_log')));
        self::assertSame(3, $db->count(Query::from('notification_queue')));
        self::assertSame(3, $db->count(Query::from('org_notification_secrets')));
        self::assertSame('0', $task->run(new ScheduledTaskRun(1, [], Principal::guest())));
    }
}
