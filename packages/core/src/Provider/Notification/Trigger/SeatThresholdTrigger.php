<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Domain\Events\SeatPoolThresholdReached;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Registry\Context\TriggerContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprint;

/**
 * The three seat threshold alerts, as three registrations of one class.
 *
 * Open edX names exactly three and this ships exactly three: seats are
 * running low, seats have run out, and the scheduled summary. They are three
 * registrations rather than three files for the same reason the two
 * invitation triggers are one class: they read one event, they differ only in
 * which reading of it they answer to, and three copies of the same
 * `parametersFor` would be three chances for one of them to freeze a
 * different number.
 *
 * **Their audience is `org_admins`, a free audience.** The pool is free by
 * design, so an alert resolved only through the Business `seat_admin` role
 * would leave a free pool exhausting with no possible recipient anywhere,
 * which is an alert that cannot be delivered rather than an alert that is
 * paid for.
 *
 * The occurrence carries the organisation, which is what lets the fan-out
 * narrow an organisation audience without an actor it does not have. Nothing
 * about a person is in the payload: this is a message about a pool.
 */
final class SeatThresholdTrigger extends AbstractEventTrigger
{
    /** The registered key of each of the three, in the shipped vidyalms.mail.* shape. */
    public const KEY_LIMITED = 'vidyalms.mail.seats_limited';
    public const KEY_EXHAUSTED = 'vidyalms.mail.seats_exhausted';
    public const KEY_PERIODIC = 'vidyalms.mail.seats_summary';

    /**
     * How few seats count as running low, when a rule says nothing.
     *
     * A count rather than a percentage, because ten per cent of a forty seat
     * pool is four and ten per cent of a four seat pool is nothing at all,
     * and an administrator with a small pool is exactly the one who needs the
     * warning.
     */
    public const DEFAULT_LOW_WATER = 5;

    public function __construct(private readonly string $threshold)
    {
        if (!\in_array($threshold, SeatPoolThresholdReached::THRESHOLDS, true)) {
            throw new \InvalidArgumentException('Unknown seat pool threshold.');
        }
    }

    /** @return list<self> */
    public static function all(): array
    {
        return [
            new self(SeatPoolThresholdReached::LIMITED),
            new self(SeatPoolThresholdReached::EXHAUSTED),
            new self(SeatPoolThresholdReached::PERIODIC),
        ];
    }

    public function key(): string
    {
        return match ($this->threshold) {
            SeatPoolThresholdReached::LIMITED => self::KEY_LIMITED,
            SeatPoolThresholdReached::EXHAUSTED => self::KEY_EXHAUSTED,
            default => self::KEY_PERIODIC,
        };
    }

    public function label(): string
    {
        return match ($this->threshold) {
            SeatPoolThresholdReached::LIMITED => 'vidyalms.trigger.seats_limited',
            SeatPoolThresholdReached::EXHAUSTED => 'vidyalms.trigger.seats_exhausted',
            default => 'vidyalms.trigger.seats_summary',
        };
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
        return 'org_admins';
    }

    public function defaultTemplateKey(): string
    {
        return $this->key();
    }

    public function eventClasses(): array
    {
        return [SeatPoolThresholdReached::class];
    }

    public function declaredPlaceholders(): array
    {
        return array_merge(parent::declaredPlaceholders(), [
            'organisation_name',
            'pool_name',
            'seats_remaining',
            'seats_capacity',
        ]);
    }

    /**
     * The subject is the pool, not the course.
     *
     * The inherited answer names the course, which is right for a learner
     * message and wrong here: two pools funding one course are two things an
     * administrator manages separately, and a queue row that could not tell
     * them apart would deduplicate one of them away.
     *
     * @return array{0: string|null, 1: int|null}
     */
    public function subjectFor(DomainEvent $event): array
    {
        return $event instanceof SeatPoolThresholdReached ? ['seat_pool', $event->poolId] : [null, null];
    }

    public function parametersFor(DomainEvent $event, TriggerContext $context): ?array
    {
        if (!$event instanceof SeatPoolThresholdReached || $event->threshold !== $this->threshold) {
            // Three triggers read one event, so the two that are not this
            // reading answer null and write no intent row at all.
            return null;
        }

        return [
            // The tenant the raising use case resolved, carried so the
            // organisation audience has something to narrow on. It is the
            // one field on this payload the fan-out itself reads.
            'organisation_id' => $event->organisationId,
            'pool_id' => $event->poolId,
            'pool_name' => $event->poolName,
            'course_id' => $event->courseId,
            'course_title' => $this->courseTitle($event->courseId, $context),
            'seats_capacity' => $event->capacity,
            'seats_remaining' => $event->remaining,
            'reconciled' => $event->reconciled,
            'threshold' => $event->threshold,
        ];
    }

    public function notificationTemplateBlueprint(): NotificationTemplateBlueprint
    {
        return CoreNotificationBlueprints::for($this->defaultTemplateKey(), $this->messageClass());
    }
}
