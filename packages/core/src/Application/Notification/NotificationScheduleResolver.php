<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationRule;
use Shondalai\VidyaLMS\Core\Domain\Notification\ScheduledOccurrence;
use Shondalai\VidyaLMS\Core\Domain\Shared\OffsetUnit;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\AnchorContext;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorRegistry;

/**
 * Turns a rule's stored schedule into the instants it actually runs at.
 *
 * It sits between the trigger match and the queue insert, which is the only
 * place it can sit: the enqueue is what mints the intent row and the
 * occurrence key, and a schedule resolved anywhere later would have to
 * rewrite a row it did not write. Without it every kind behaves as immediate,
 * a seven-day nudge fires on day zero, and a recurring window bounded at
 * eleven occurrences produces one row.
 *
 * Two rules from the design are load-bearing here rather than decorative. A
 * day-valued offset or recurrence steps the anchor's **local calendar**, so a
 * weekly recurrence spanning a daylight-saving transition lands on the same
 * local wall-clock time every week. And an anchor this site cannot resolve
 * **fails closed**: the occurrence is not enqueued at all and the miss is
 * logged, never silently retimed to now, because "the anchor is missing so
 * send it immediately" is the one miss whose obvious reading is the dangerous
 * one.
 */
final class NotificationScheduleResolver
{
    /**
     * The hard ceiling on one recurring window, so a rule authored with a
     * one-hour period and a ten-year bound cannot enqueue without bound. It
     * is far above any legitimate authoring and it is logged when it bites.
     */
    public const MAX_OCCURRENCES = 500;

    public function __construct(
        private readonly ScheduleAnchorRegistry $anchors,
        private readonly ClockPort $clock,
        private readonly DatabasePort $database,
        private readonly LoggerInterface $logger,
        private readonly string $siteTimezone = 'UTC',
    ) {
    }

    /**
     * Every instant this rule fires at for this occurrence, or an empty list
     * when the schedule cannot be resolved.
     *
     * @param array<string, mixed> $parameters the trigger's own parameter bag, which is where the learner and course ids are
     *
     * @return list<ScheduledOccurrence>
     */
    public function occurrencesFor(
        NotificationRule $rule,
        array $parameters,
        string $baseOccurrenceKey,
        ?\DateTimeImmutable $runAt = null,
    ): array {
        if ($runAt !== null) {
            // A sweep occurrence already carries the instant its own trigger
            // decided; a rule schedule does not get to move it.
            return [ScheduledOccurrence::once($runAt, $baseOccurrenceKey)];
        }

        $now = $this->clock->now();

        return match ($rule->scheduleKind) {
            NotificationRule::KIND_ABSOLUTE => $this->absolute($rule, $baseOccurrenceKey),
            NotificationRule::KIND_RELATIVE => $this->relative($rule, $parameters, $baseOccurrenceKey),
            NotificationRule::KIND_RECURRING_WINDOW => $this->recurring($rule, $parameters, $baseOccurrenceKey),
            default => [ScheduledOccurrence::once($now, $baseOccurrenceKey)],
        };
    }

    /**
     * @return list<ScheduledOccurrence>
     */
    private function absolute(NotificationRule $rule, string $baseOccurrenceKey): array
    {
        $at = $this->instantOf($rule->schedule, 'at');

        if ($at === null) {
            $this->unresolved($rule, 'absolute schedule carries no instant');

            return [];
        }

        return [ScheduledOccurrence::once($at, $baseOccurrenceKey)];
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<ScheduledOccurrence>
     */
    private function relative(NotificationRule $rule, array $parameters, string $baseOccurrenceKey): array
    {
        $resolved = $this->anchorInstant($rule, $parameters);

        if ($resolved === null) {
            return [];
        }

        [$anchorAt, $timezone] = $resolved;

        $offset = $this->intOf($rule->schedule, 'offset_seconds', 0);
        $unit = $this->unitOf($rule->schedule, 'offset_unit');

        if ($this->stringOf($rule->schedule, 'direction', 'after') === 'before') {
            $offset = -$offset;
        }

        return [ScheduledOccurrence::once($unit->add($anchorAt, $offset, $timezone), $baseOccurrenceKey)];
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return list<ScheduledOccurrence>
     */
    private function recurring(NotificationRule $rule, array $parameters, string $baseOccurrenceKey): array
    {
        $resolved = $this->anchorInstant($rule, $parameters);

        if ($resolved === null) {
            return [];
        }

        [$anchorAt, $timezone] = $resolved;

        $every = $this->intOf($rule->schedule, 'every', 0);
        $everyUnit = $this->unitOf($rule->schedule, 'every_unit');
        $until = $this->intOf($rule->schedule, 'until', 0);
        $untilUnit = $this->unitOf($rule->schedule, 'until_unit');

        if ($every < 1 || $until < 1) {
            $this->unresolved($rule, 'recurring window carries no period or no bound');

            return [];
        }

        $last = $untilUnit->step($anchorAt, $until, $timezone);
        $occurrences = [];
        $ordinal = 0;
        $cursor = $anchorAt;

        while ($cursor <= $last) {
            ++$ordinal;
            $occurrences[] = ScheduledOccurrence::ordinal($cursor, $baseOccurrenceKey, $ordinal);

            if ($ordinal >= self::MAX_OCCURRENCES) {
                $this->logger->notice('VidyaLMS notification recurrence hit the occurrence ceiling.', [
                    'rule' => $rule->ruleKey,
                    'ceiling' => self::MAX_OCCURRENCES,
                ]);
                break;
            }

            $next = $everyUnit->step($anchorAt, $every * $ordinal, $timezone);

            if ($next <= $cursor) {
                break;
            }

            $cursor = $next;
        }

        return $occurrences;
    }

    /**
     * The anchor's instant and the zone its arithmetic runs in, or null when
     * nothing on this site resolves the key the rule names.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array{0: \DateTimeImmutable, 1: string}|null
     */
    private function anchorInstant(NotificationRule $rule, array $parameters): ?array
    {
        $anchorKey = $rule->anchorKey ?? '';

        if ($anchorKey === '' || !$this->anchors->has($anchorKey)) {
            $this->unresolved($rule, sprintf('anchor "%s" is not registered', $anchorKey));

            return null;
        }

        $anchor = $this->anchors->get($anchorKey);
        $context = $this->anchorContext($rule, $parameters);
        $instant = $anchor->resolve($context);

        if ($instant === null) {
            $this->unresolved($rule, sprintf('anchor "%s" resolved nothing', $anchorKey));

            return null;
        }

        return [$instant, $anchor->timezoneFor($context)];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function anchorContext(NotificationRule $rule, array $parameters): AnchorContext
    {
        $userId = $this->intOf($parameters, 'user_id', 0);
        $courseId = $this->intOf($parameters, 'course_id', $rule->courseId ?? 0);

        $course = $courseId > 0
            ? $this->database->selectOne(Query::from('courses')->where('id', '=', $courseId))
            : null;

        $enrolment = null;
        $entitlements = [];

        if ($userId > 0 && $courseId > 0) {
            $enrolment = $this->database->selectOne(
                Query::from('enrolments')
                    ->where('user_id', '=', $userId)
                    ->where('course_id', '=', $courseId)
                    ->orderBy('id', Query::SORT_DESC),
            );

            $entitlements = $this->database->selectAll(
                Query::from('entitlements')
                    ->where('user_id', '=', $userId)
                    ->where('course_id', '=', $courseId),
            );
        }

        return AnchorContext::create(
            $userId > 0 ? Principal::user($userId) : Principal::guest(),
            $this->clock->now(),
            $enrolment,
            $course,
            $rule->schedule,
            $this->siteTimezone,
            [AvailabilityContext::FACT_ENTITLEMENTS => $entitlements],
        );
    }

    private function unresolved(NotificationRule $rule, string $why): void
    {
        $this->logger->notice('VidyaLMS notification schedule did not resolve; nothing was enqueued.', [
            'rule' => $rule->ruleKey,
            'schedule_kind' => $rule->scheduleKind,
            'reason' => $why,
        ]);
    }

    /**
     * @param array<string, mixed> $document
     */
    private function instantOf(array $document, string $key): ?\DateTimeImmutable
    {
        $value = $document[$key] ?? null;

        if (!\is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private function intOf(array $document, string $key, int $default = 0): int
    {
        $value = $document[$key] ?? null;

        return \is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function stringOf(array $document, string $key, string $default = ''): string
    {
        $value = $document[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function unitOf(array $document, string $key): OffsetUnit
    {
        return OffsetUnit::tryFrom($this->stringOf($document, $key, OffsetUnit::Days->value)) ?? OffsetUnit::Days;
    }
}
