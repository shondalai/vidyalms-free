<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor;

use Shondalai\VidyaLMS\Core\Registry\Context\AnchorContext;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\DeclaresAnchorParams;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorRegistry;

/**
 * A duration after another anchor, which is the commonest delay there is and
 * the one nothing shipped could express.
 *
 * Five anchors were registered and every one of them answered an instant that
 * already exists: an authored datetime, the enrolment, the entitlement start,
 * the course start, and the later of two of those. None applied an offset and
 * the fan-out applied none of its own, so of the six delay kinds the design
 * maps onto this registry exactly one worked. "Wait three days after
 * enrolment" resolved to the enrolment instant, which is in the past, and the
 * past-anchor rule then fired it immediately: the delay was silently nothing.
 *
 * **The offset is applied in the base anchor's own calendar and never as N
 * times 86400 seconds.** A DST transition inside the window otherwise lands
 * day seven an hour early or late, which is the whole reason the seam declares
 * a timezone method at all.
 */
final class RelativeAnchor implements ScheduleAnchorInterface, DeclaresAnchorParams
{
    public const KEY = 'relative';

    /** The unit words an author may write, mapped to their calendar step. */
    private const UNITS = [
        'minutes' => 'minutes',
        'hours' => 'hours',
        'days' => 'days',
        'weeks' => 'weeks',
        'months' => 'months',
    ];

    public function __construct(private readonly ScheduleAnchorRegistry $anchors)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.anchor.relative';
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['base_anchor_key', 'amount', 'unit'],
            'additionalProperties' => false,
            'properties' => [
                'base_anchor_key' => ['type' => 'string', 'minLength' => 1],
                'amount' => ['type' => 'integer'],
                'unit' => ['type' => 'string', 'enum' => array_keys(self::UNITS)],
                'tz' => ['type' => 'string'],
                'at' => ['type' => 'string'],
                'from' => ['type' => 'string'],
            ],
        ];
    }

    public function resolve(AnchorContext $context): ?\DateTimeImmutable
    {
        $baseKey = $context->payload['base_anchor_key'] ?? null;

        if (!\is_string($baseKey) || $baseKey === '' || $baseKey === self::KEY) {
            // Refusing to resolve itself is not tidiness: a relative anchor
            // whose base is itself is an unbounded recursion inside a fan-out.
            return null;
        }

        if (!$this->anchors->has($baseKey)) {
            // The miss direction the seam publishes: an unresolved anchor
            // leaves the thing blocked, never immediately due.
            return null;
        }

        $base = $this->anchors->get($baseKey)->resolve($context);

        if ($base === null) {
            return null;
        }

        $amount = $context->payload['amount'] ?? null;
        $unit = $context->payload['unit'] ?? null;

        if (!\is_int($amount) || !\is_string($unit) || !isset(self::UNITS[$unit])) {
            return null;
        }

        $zone = new \DateTimeZone($this->timezoneFor($context));
        $local = $base->setTimezone($zone);
        $moved = $local->modify(sprintf('%+d %s', $amount, self::UNITS[$unit]));

        return $moved === false ? null : $moved->setTimezone(new \DateTimeZone('UTC'));
    }

    public function timezoneFor(AnchorContext $context): string
    {
        $timezone = $context->payload['tz'] ?? null;

        if (\is_string($timezone) && $timezone !== '' && \in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            return $timezone;
        }

        return $context->resolvedTimezone();
    }
}
