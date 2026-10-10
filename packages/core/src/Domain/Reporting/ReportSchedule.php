<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Reporting;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryContext;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * One row of `report_schedules`, as the domain sees it.
 *
 * It takes Moodle's proven field set rather than a cron string: a name, an
 * export registry key, a delivery registry key, a recurrence, a start moment
 * with an **explicit** timezone, recipients as an **audience** rather than as a
 * list of addresses, a subject and body, an empty-report policy with three
 * outcomes, and a `render_as` setting. A cron expression cannot carry a
 * timezone, and a schedule with no timezone is a set of DST bugs waiting for
 * the last Sunday in October.
 *
 * **`renderAs` is the security-critical setting and its default is the
 * recipient.** Rendering the run once with the author's visibility and mailing
 * the same file to a mixed audience is a data breach the first time a site has
 * two group leaders, and the thing it looks like a shortcut for, one schedule
 * serving twenty leaders, is exactly what rendering per recipient already does
 * correctly. Author scope is an explicit choice, and
 * {@see \Shondalai\VidyaLMS\Core\Domain\Reporting\DeliveryBoundaryPolicy}
 * refuses it outright once the audience spans more than one organisation.
 *
 * The definition and the audience config are
 * {@see StoredShape}-wrapped with their version mirrored into a column of their
 * own, exactly as `cohorts.schedule` and `notification_rules.schedule` already
 * are. A second schedule shape in one product is a second set of the same bugs,
 * and a JSON version with no column beside it is a migration that has to walk
 * every row to find the rows it must walk.
 */
final class ReportSchedule
{
    public const RENDER_AS_RECIPIENT = DeliveryContext::RENDER_AS_RECIPIENT;
    public const RENDER_AS_AUTHOR = DeliveryContext::RENDER_AS_AUTHOR;

    /** @var list<string> */
    public const RENDER_MODES = [self::RENDER_AS_RECIPIENT, self::RENDER_AS_AUTHOR];

    public const RECURRENCE_DAILY = 'daily';
    public const RECURRENCE_WEEKLY = 'weekly';
    public const RECURRENCE_MONTHLY = 'monthly';

    /** @var list<string> */
    public const RECURRENCES = [
        self::RECURRENCE_DAILY,
        self::RECURRENCE_WEEKLY,
        self::RECURRENCE_MONTHLY,
    ];

    /** Send the empty report, so a recipient learns the answer was nothing. */
    public const EMPTY_SEND = 'send';

    /** Send the message and no file, because a zero-row CSV is a confusing attachment. */
    public const EMPTY_SEND_WITHOUT_FILE = 'send_without_file';

    /** Say nothing at all, which is what a daily exception report wants. */
    public const EMPTY_SKIP = 'skip';

    /** @var list<string> */
    public const EMPTY_POLICIES = [
        self::EMPTY_SEND,
        self::EMPTY_SEND_WITHOUT_FILE,
        self::EMPTY_SKIP,
    ];

    public const STATE_ENABLED = 'enabled';
    public const STATE_DISABLED = 'disabled';

    /** The shape this build writes for the stored definition body. */
    public const DEFINITION_SHAPE = 1;

    /**
     * @param array<string, mixed> $definition     the saved parameter set, body only
     * @param array<string, mixed> $audienceConfig the audience provider's own shape, body only
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $title,
        public readonly string $reportKey,
        public readonly string $formatKey,
        public readonly string $deliveryKey,
        public readonly array $definition,
        public readonly int $definitionVersion,
        public readonly string $audienceKey,
        public readonly array $audienceConfig,
        public readonly int $audienceConfigVersion,
        public readonly string $recurrence,
        public readonly int $recurrenceDay,
        public readonly int $runAtMinute,
        public readonly string $timezone,
        public readonly \DateTimeImmutable $startsAt,
        public readonly ?\DateTimeImmutable $nextRunAt,
        public readonly ?\DateTimeImmutable $lastRunAt,
        public readonly string $renderAs,
        public readonly string $emptyPolicy,
        public readonly ?string $subject,
        public readonly ?string $body,
        public readonly ?int $organisationId,
        public readonly string $state,
        public readonly int $createdBy,
        public readonly int $runCount = 0,
        public readonly ?string $lastError = null,
    ) {
    }

    /**
     * A new schedule, with every default the security argument above asks for.
     *
     * The defaults are here and not in a caller because a caller that forgets
     * `render_as` must get the recipient, never the author: the safe value of a
     * cross-tenant setting is the one a forgetful caller lands on.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $audienceConfig
     */
    public static function create(
        string $title,
        string $reportKey,
        string $audienceKey,
        \DateTimeImmutable $startsAt,
        int $createdBy,
        string $formatKey = 'csv',
        string $deliveryKey = 'notification-link',
        array $definition = [],
        array $audienceConfig = [],
        string $recurrence = self::RECURRENCE_DAILY,
        int $recurrenceDay = 1,
        int $runAtMinute = 0,
        string $timezone = 'UTC',
        string $renderAs = self::RENDER_AS_RECIPIENT,
        string $emptyPolicy = self::EMPTY_SEND,
        ?string $subject = null,
        ?string $body = null,
        ?int $organisationId = null,
        string $state = self::STATE_DISABLED,
    ): self {
        if (trim($title) === '') {
            throw ValidationException::forField('title', 'A report schedule is named.');
        }
        if ($subject !== null && (strlen($subject) > 1000 || strpbrk($subject, "\r\n") !== false)) {
            throw ValidationException::forField('subject', 'Use a single-line subject of at most 1,000 bytes.');
        }
        if ($body !== null && strlen($body) > 20000) {
            throw ValidationException::forField('body', 'The report message may contain at most 20,000 bytes.');
        }

        if (!\in_array($recurrence, self::RECURRENCES, true)) {
            throw ValidationException::forField('recurrence', sprintf(
                'A report schedule recurs %s. It cannot recur "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', self::RECURRENCES),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $recurrence,
            ));
        }

        if (!\in_array($renderAs, self::RENDER_MODES, true)) {
            throw ValidationException::forField('render_as', sprintf(
                'A report schedule renders per %s. It cannot render "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(' or per ', self::RENDER_MODES),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $renderAs,
            ));
        }

        if (!\in_array($emptyPolicy, self::EMPTY_POLICIES, true)) {
            throw ValidationException::forField('empty_policy', sprintf(
                'An empty report is %s. It cannot be "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', self::EMPTY_POLICIES),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $emptyPolicy,
            ));
        }

        // The timezone is the identity of every boundary this schedule
        // computes, so an unknown one is refused at save time rather than
        // silently read as UTC at 03:00 in somebody else's winter.
        if (!\in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw ValidationException::forField('timezone', sprintf(
                '"%s" is not a timezone this build knows.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $timezone,
            ));
        }

        if ($runAtMinute < 0 || $runAtMinute > 1439) {
            throw ValidationException::forField('run_at_minute', 'A run minute is inside one day.');
        }

        return new self(
            null,
            trim($title),
            $reportKey,
            $formatKey,
            $deliveryKey,
            StoredShape::bodyOf($definition),
            self::DEFINITION_SHAPE,
            $audienceKey,
            StoredShape::bodyOf($audienceConfig),
            1,
            $recurrence,
            max(1, $recurrenceDay),
            $runAtMinute,
            $timezone,
            $startsAt,
            null,
            null,
            $renderAs,
            $emptyPolicy,
            $subject,
            $body,
            $organisationId,
            $state,
            $createdBy,
        );
    }

    /** Whether this schedule renders one artefact per recipient under their own scope. */
    public function rendersPerRecipient(): bool
    {
        return $this->renderAs !== self::RENDER_AS_AUTHOR;
    }

    /**
     * The next moment this schedule is due, computed in its own timezone.
     *
     * The arithmetic is done in the schedule's zone and converted back to UTC
     * at the end, which is the only order that survives a DST transition: a
     * daily 09:00 report must stay at 09:00 local across the spring forward,
     * and adding 86400 seconds to a UTC instant moves it to 08:00 or 10:00
     * exactly once a year in a way nobody notices until an auditor does.
     */
    public function nextDueAfter(\DateTimeImmutable $after): \DateTimeImmutable
    {
        $zone = new \DateTimeZone($this->timezone);
        $from = $after < $this->startsAt ? $this->startsAt : $after;
        $local = $from->setTimezone($zone);
        $candidate = $local->setTime(intdiv($this->runAtMinute, 60), $this->runAtMinute % 60);

        // Walk forward rather than compute, because a monthly schedule on the
        // 31st has to land on a real date and a weekly one has to cross a
        // DST boundary. Bounded: the longest step is a month, so 400 turns
        // covers a year and a half of any recurrence.
        for ($turn = 0; $turn < 400; $turn++) {
            if ($candidate > $local && $this->matchesRecurrence($candidate)) {
                return $candidate->setTimezone(new \DateTimeZone('UTC'));
            }

            $candidate = $candidate->modify('+1 day')
                ->setTime(intdiv($this->runAtMinute, 60), $this->runAtMinute % 60);
        }

        throw new \RuntimeException('A report schedule found no next occurrence inside four hundred days.');
    }

    /**
     * The period bucket this run belongs to, in the schedule's own timezone.
     *
     * It is what the notification occurrence reference is minted from, so two
     * cron ticks inside one period are one message rather than two.
     */
    public function periodBucket(\DateTimeImmutable $at): string
    {
        $local = $at->setTimezone(new \DateTimeZone($this->timezone));

        return match ($this->recurrence) {
            self::RECURRENCE_WEEKLY => $local->format('o-\WW'),
            self::RECURRENCE_MONTHLY => $local->format('Y-m'),
            default => $local->format('Y-m-d'),
        };
    }

    private function matchesRecurrence(\DateTimeImmutable $candidate): bool
    {
        return match ($this->recurrence) {
            self::RECURRENCE_WEEKLY => (int) $candidate->format('N') === max(1, min(7, $this->recurrenceDay)),
            self::RECURRENCE_MONTHLY => (int) $candidate->format('j') === $this->dayOfMonthIn($candidate),
            default => true,
        };
    }

    /**
     * The 31st of a thirty-day month is its last day and never the 1st of the
     * next one, because a monthly report that skips April is a report nobody
     * can reconcile.
     */
    private function dayOfMonthIn(\DateTimeImmutable $candidate): int
    {
        return min($this->recurrenceDay, (int) $candidate->format('t'));
    }
}
