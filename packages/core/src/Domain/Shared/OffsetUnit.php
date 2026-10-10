<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Shared;

/**
 * How an offset in seconds is actually stepped from its anchor.
 *
 * The value is always stored in seconds so nothing has to decode a document
 * to compare two offsets, and the unit says how to add it. "hours" adds the
 * seconds; "days" divides by 86400 and steps that many days in the anchor's
 * local calendar, so week seven crossing a daylight-saving transition lands
 * at the same wall-clock time rather than an hour early or late.
 *
 * Both readings are authorable and neither is a surprise, which is the other
 * reason the unit exists: one well-known platform documents that its "1 day"
 * means 24 hours after the enrolment instant and not the next calendar day,
 * and an author who wants each is entitled to say which.
 *
 * It lives in the core because the notification schedule enum owns it as much
 * as the cohort schedule does: a recurrence carries a unit and steps the
 * anchor's local calendar for exactly the same reason a relative offset does.
 */
enum OffsetUnit: string
{
    case Hours = 'hours';
    case Days = 'days';

    public const SECONDS_PER_DAY = 86400;
    public const SECONDS_PER_HOUR = 3600;

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? throw ValidationException::forField(
            'offset_unit',
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            sprintf('"%s" is not an offset unit; it is "hours" or "days".', $value),
        );
    }

    /**
     * Adds a signed offset in seconds to an instant, in the anchor's own zone
     * when the unit is days.
     *
     * The instant is converted into the zone, stepped by whole days there,
     * and converted back, which is the only arithmetic that survives a
     * transition. A remainder of seconds below a whole day is added after the
     * day step rather than being dropped, so an author who says "seven days
     * and two hours" gets both halves.
     */
    public function add(\DateTimeImmutable $anchor, int $offsetSeconds, string $timezone): \DateTimeImmutable
    {
        if ($this === self::Hours) {
            return $anchor->modify(sprintf('%+d seconds', $offsetSeconds));
        }

        $zone = self::zone($timezone);
        $days = intdiv($offsetSeconds, self::SECONDS_PER_DAY);
        $remainder = $offsetSeconds - ($days * self::SECONDS_PER_DAY);

        $local = $anchor->setTimezone($zone)->modify(sprintf('%+d days', $days));

        if ($remainder !== 0) {
            $local = $local->modify(sprintf('%+d seconds', $remainder));
        }

        return $local->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * One step of a recurrence, expressed in this unit's own quantity rather
     * than in seconds, because "every 7 days" is authored as 7 and not as
     * 604800 and stepping the calendar needs the count.
     */
    public function step(\DateTimeImmutable $instant, int $quantity, string $timezone): \DateTimeImmutable
    {
        return $this->add($instant, $quantity * $this->seconds(), $timezone);
    }

    public function seconds(): int
    {
        return $this === self::Hours ? self::SECONDS_PER_HOUR : self::SECONDS_PER_DAY;
    }

    /**
     * A zone name the site stored that PHP no longer recognises must not take
     * a schedule read down. UTC is the honest fallback: it is what the column
     * defaults to and it is what every instant is stored in.
     */
    private static function zone(string $timezone): \DateTimeZone
    {
        try {
            return new \DateTimeZone($timezone);
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }
}
