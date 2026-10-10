<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\SweepContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\DeclaresNotificationTemplateBlueprint;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTriggerInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprint;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\SweepTriggerInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\TriggerOccurrence;

/**
 * Access ends in N days.
 *
 * The free sweep, and it exists so the sweep seam has a free provider and a
 * free test: the "run the sweep twice in one period, assert exactly one queue
 * row" test needs one. Its rule is seeded disabled, and enabling it depends
 * on a rule this design imposes on itself, that a fact may not exist only in
 * mail: the admin sees an expiry fourteen days ahead while the learner sees
 * nothing until after it has happened, so the screen has to come first.
 *
 * The occurrence key is the period bucket, and the reference it mints is
 * synthetic because no event exists for the absence of an event. Without
 * both, a cron that runs twice in one period sends twice, which is a defect
 * shipped elsewhere with one-time-only switched on.
 */
final class EntitlementExpiringSweep implements
    SweepTriggerInterface,
    DeclaresNotificationTemplateBlueprint,
    \Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\DeclaresSubjectTypes
{
    public const KEY = 'vidyalms.sweep.entitlement_expiring';

    /** How many days ahead a rule looks, unless its own definition says otherwise. */
    public const DEFAULT_DAYS_AHEAD = 14;

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.trigger.entitlement_expiring';
    }

    /**
     * A learner and the course their access is expiring on.
     *
     * The two are not the same person and that is the whole point of
     * declaring both: this sweep yields the learner as the recipient and the
     * course as the subject, and reading the subject id as a user id is what
     * queued a learner's expiry notice to whoever held that course id.
     *
     * @return list<string>
     */
    public function subjectTypes(): array
    {
        return ['user', 'course'];
    }

    public function kind(): string
    {
        return NotificationTriggerInterface::KIND_SWEEP;
    }

    public function categoryKey(): string
    {
        return 'account';
    }

    public function messageClass(): NotificationClass
    {
        return NotificationClass::Operational;
    }

    public function defaultAudienceKey(): string
    {
        return 'learner';
    }

    public function defaultTemplateKey(): string
    {
        return self::KEY;
    }

    public function declaredPlaceholders(): array
    {
        return [
            'user.name',
            'user.first_name',
            'user.email',
            'site.name',
            'site.url',
            'course.title',
        ];
    }

    public function notificationTemplateBlueprint(): NotificationTemplateBlueprint
    {
        return CoreNotificationBlueprints::for($this->defaultTemplateKey(), $this->messageClass());
    }

    public function dueCandidates(SweepContext $context): iterable
    {
        $days = $context->intSetting('days_ahead', self::DEFAULT_DAYS_AHEAD);
        $horizon = $context->now->modify('+' . $days . ' days');

        $rows = $context->database->selectAll(
            Query::from('entitlements')
                ->select(['id', 'user_id', 'course_id', 'expires_at'])
                ->where('status', '=', 'active')
                ->whereNotNull('expires_at')
                ->where('expires_at', '>', $context->now->format('Y-m-d H:i:s'))
                ->where('expires_at', '<=', $horizon->format('Y-m-d H:i:s'))
                ->orderBy('expires_at'),
        );

        foreach ($rows as $row) {
            $entitlementId = (int) $row['id'];

            // The shard. One run does bounded work and a learner stays in the
            // same bin between runs, so a bounded run is still a complete run
            // across the set.
            if (!$context->ownsShard($entitlementId)) {
                continue;
            }

            yield TriggerOccurrence::forSweep(
                self::KEY,
                (int) $row['user_id'],
                $context->periodBucket,
                'course',
                (int) $row['course_id'],
                [
                    'entitlement_id' => $entitlementId,
                    'course_id' => (int) $row['course_id'],
                    'expires_at' => (string) $row['expires_at'],
                    'days_ahead' => $days,
                    'recipient_user_id' => (int) $row['user_id'],
                ],
            );
        }
    }
}
