<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationDigest;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationRule;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueuedNotification;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueueState;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TriggerOccurrence;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The automation engine's entry into the outbox, before the engine exists.
 *
 * Two producers number their rules independently. Everything else about the
 * two rows here is identical: one trigger, one template, one channel, one
 * recipient and one occurrence, which is the case the queue's unique index
 * turned into one row and one silent loss.
 */
final class AutomationStepEnqueueTest extends TestCase
{
    private const TRIGGER = 'vidyalms.mail.enrolled';
    private const TEMPLATE = 'vidyalms.mail.enrolled';
    private const CATEGORY = 'course_progress';

    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-04-01 10:00:00');
    }

    public function testAnAutomationRuleAndANotificationRuleOfOneIdNoLongerCollide(): void
    {
        $ruleId = $this->seedRule();
        $occurrence = $this->occurrence();

        $notificationRow = $this->fixture->kernel->notificationQueue->enqueue(new QueuedNotification(
            null,
            $ruleId,
            self::TRIGGER,
            self::CATEGORY,
            NotificationClass::Operational,
            self::TEMPLATE,
            $occurrence->eventReference,
            $occurrence->occurrenceKey,
            NotificationDigest::forDelivery(
                NotificationDigest::notificationRuleRef($ruleId),
                self::TRIGGER,
                $occurrence->eventReference,
                self::TEMPLATE,
                'email',
                ManagementFixture::LEARNER_ID,
                $occurrence->occurrenceKey,
            ),
            $this->fixture->env->clock->now(),
            QueueState::Pending,
            ManagementFixture::LEARNER_ID,
            'email',
        ));

        self::assertNotNull($notificationRow);

        // The same integer, from the other producer.
        $automationRow = $this->fixture->kernel->notificationEnqueue->forAutomationStep(
            $ruleId,
            self::TRIGGER,
            self::TEMPLATE,
            self::CATEGORY,
            NotificationClass::Operational,
            'email',
            $occurrence,
        );

        self::assertNotNull($automationRow, 'the automation step was swallowed by the notification rule of the same id');
        self::assertNotSame($notificationRow, $automationRow);
        self::assertSame(2, $this->fixture->env->database->count(
            Query::from('notification_queue')->where('event_ref', '=', $occurrence->eventReference),
        ));

        // The defect, stated in the test rather than only in the docblock:
        // the pre-change digest was computed over the bare id, so these two
        // rows hashed identically and the second insert answered null.
        $legacy = static fn (): string => hash('sha256', implode('|', [
            (string) $ruleId,
            self::TRIGGER,
            $occurrence->eventReference,
            self::TEMPLATE,
            'email',
            (string) ManagementFixture::LEARNER_ID,
            $occurrence->occurrenceKey,
        ]));

        self::assertSame($legacy(), $legacy());
    }

    public function testAnAutomationStepTouchesNoNotificationRuleRowOrCounter(): void
    {
        $ruleId = $this->seedRule();

        $this->fixture->kernel->notificationEnqueue->forAutomationStep(
            $ruleId,
            self::TRIGGER,
            self::TEMPLATE,
            self::CATEGORY,
            NotificationClass::Operational,
            'email',
            $this->occurrence(),
        );

        $rule = $this->fixture->kernel->notificationRules->find($ruleId);
        self::assertNotNull($rule);
        self::assertSame(0, $rule->queuedCount, 'an automation step incremented a notification rule counter');

        $row = $this->fixture->env->database->selectOne(
            Query::from('notification_queue')->where('recipient_user_id', '=', ManagementFixture::LEARNER_ID),
        );
        self::assertNotNull($row);
        self::assertNull($row['rule_id'], 'an automation rule id was stored as a notification rule id');
        self::assertSame(QueueState::Pending->value, $row['state']);
        self::assertSame('email', $row['channel_key']);
    }

    public function testAReplayedAutomationStepEnqueuesNothingTwice(): void
    {
        $occurrence = $this->occurrence();
        $enqueue = $this->fixture->kernel->notificationEnqueue;

        $first = $enqueue->forAutomationStep(
            7,
            self::TRIGGER,
            self::TEMPLATE,
            self::CATEGORY,
            NotificationClass::Operational,
            'email',
            $occurrence,
        );
        $second = $enqueue->forAutomationStep(
            7,
            self::TRIGGER,
            self::TEMPLATE,
            self::CATEGORY,
            NotificationClass::Operational,
            'email',
            $occurrence,
        );

        self::assertNotNull($first);
        self::assertNull($second);
    }

    public function testAStepWithNoRecipientIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->fixture->kernel->notificationEnqueue->forAutomationStep(
            7,
            self::TRIGGER,
            self::TEMPLATE,
            self::CATEGORY,
            NotificationClass::Operational,
            'email',
            TriggerOccurrence::forSweep(self::TRIGGER, 0, '2026-04-01'),
        );
    }

    private function occurrence(): TriggerOccurrence
    {
        return TriggerOccurrence::forSweep(
            self::TRIGGER,
            ManagementFixture::LEARNER_ID,
            '2026-04-01',
            'course',
            $this->fixture->courseId,
        );
    }

    private function seedRule(): int
    {
        $rule = $this->fixture->kernel->notificationRules->save(new NotificationRule(
            null,
            'test.automation.collision',
            self::TRIGGER,
            'learner',
            self::CATEGORY,
            self::TEMPLATE,
            ['email'],
            state: NotificationRule::STATE_ENABLED,
        ));

        return $rule->id ?? self::fail('the seeded rule has no id');
    }
}
