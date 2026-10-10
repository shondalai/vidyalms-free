<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Notification;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationDigest;

/**
 * The queue's dedupe digest identifies a producer as well as a rule.
 *
 * A bare integer rule id is not an identity: notification rule 42 and
 * automation rule 42 are two different rules, and on one trigger and one
 * occurrence they produced one SHA-256, so the unique index on
 * notification_queue silently dropped whichever arrived second.
 */
final class NotificationDigestTest extends TestCase
{
    private const TRIGGER = 'vidyalms.mail.enrolled';
    private const REFERENCE = 'enrolment:7';
    private const OCCURRENCE = 'once';

    public function testTwoProducersOfOneRuleIdNoLongerCollideOnTheIntentDigest(): void
    {
        $notification = NotificationDigest::forIntent(
            NotificationDigest::notificationRuleRef(42),
            self::TRIGGER,
            self::REFERENCE,
            self::OCCURRENCE,
        );
        $automation = NotificationDigest::forIntent(
            NotificationDigest::automationRuleRef(42),
            self::TRIGGER,
            self::REFERENCE,
            self::OCCURRENCE,
        );

        self::assertNotSame($notification, $automation);
    }

    public function testTwoProducersOfOneRuleIdNoLongerCollideOnTheDeliveryDigest(): void
    {
        $arguments = [self::TRIGGER, self::REFERENCE, 'vidyalms.mail.enrolled', 'email', 9, self::OCCURRENCE];

        self::assertNotSame(
            NotificationDigest::forDelivery(NotificationDigest::notificationRuleRef(42), ...$arguments),
            NotificationDigest::forDelivery(NotificationDigest::automationRuleRef(42), ...$arguments),
        );
    }

    public function testTwoProducersOfOneRuleIdNoLongerCollideOnTheDigestItem(): void
    {
        $arguments = [self::TRIGGER, self::REFERENCE, 'course_progress', 'email', 9, self::OCCURRENCE, 'daily'];

        self::assertNotSame(
            NotificationDigest::forDigestItem(NotificationDigest::notificationRuleRef(42), ...$arguments),
            NotificationDigest::forDigestItem(NotificationDigest::automationRuleRef(42), ...$arguments),
        );
    }

    /**
     * A rule-less occurrence is still a notification one, so the two shapes
     * that carry no rule (a sweep with no rule row, a Pro digest) stay
     * distinct from an automation rule numbered zero.
     */
    public function testAnAbsentRuleIdIsStillProducerQualified(): void
    {
        self::assertSame('notification:0', NotificationDigest::notificationRuleRef(null));
        self::assertSame('automation:0', NotificationDigest::automationRuleRef(null));
        self::assertNotSame(
            NotificationDigest::forIntent(
                NotificationDigest::notificationRuleRef(null),
                self::TRIGGER,
                self::REFERENCE,
                self::OCCURRENCE,
            ),
            NotificationDigest::forIntent(
                NotificationDigest::automationRuleRef(null),
                self::TRIGGER,
                self::REFERENCE,
                self::OCCURRENCE,
            ),
        );
    }

    public function testAReferenceThatNamesNoProducerIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        NotificationDigest::forIntent('42', self::TRIGGER, self::REFERENCE, self::OCCURRENCE);
    }

    /**
     * The discontinuity, asserted rather than assumed: a row written before
     * this change keeps the digest it was written with, and the same rule
     * now produces a different one. Rows on either side of the change cannot
     * dedupe against each other, which is why it landed while both queues
     * held only development rows.
     */
    public function testDigestsWrittenBeforeTheProducerQualifierAreNotReproduced(): void
    {
        $legacy = hash('sha256', implode('|', [
            'fanout',
            '42',
            self::TRIGGER,
            self::REFERENCE,
            self::OCCURRENCE,
        ]));

        self::assertNotSame($legacy, NotificationDigest::forIntent(
            NotificationDigest::notificationRuleRef(42),
            self::TRIGGER,
            self::REFERENCE,
            self::OCCURRENCE,
        ));
    }

    public function testTheUnchangedDigestsAreUnchanged(): void
    {
        self::assertSame(
            hash('sha256', 'enrolment:7|vidyalms.mail.enrolled|learner@example.test'),
            NotificationDigest::forEmailLog('enrolment:7', 'vidyalms.mail.enrolled', 'learner@example.test'),
        );
        self::assertSame(hash('sha256', 'learner@example.test'), NotificationDigest::forAddress(' Learner@Example.test '));
    }
}
