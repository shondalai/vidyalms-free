<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityReason;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor\AnchorInstant;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionInterface;
use Shondalai\VidyaLMS\Core\Registry\Availability\BatchEvaluatesItems;
use Shondalai\VidyaLMS\Core\Registry\Availability\ConditionMetadataInterface;
use Shondalai\VidyaLMS\Core\Registry\Availability\DeclaresExhaustion;
use Shondalai\VidyaLMS\Core\Registry\Availability\NamesOpensAt;
use Shondalai\VidyaLMS\Core\Registry\Availability\PayloadValidationResult;
use Shondalai\VidyaLMS\Core\Registry\Availability\SupportsPayloadVersions;
use Shondalai\VidyaLMS\Core\Registry\Availability\ValidatesPayload;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Registry\Context\DescriptionContext;
use Shondalai\VidyaLMS\Core\Registry\Context\PayloadValidationContext;
use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;

/**
 * An absolute window: from inclusive, until exclusive, both nullable but not
 * both null.
 *
 * A window entirely in the past returns blocked with no opensAt and
 * exhausted true, never never. Both halves of that are load-bearing. A date
 * condition is time-like and an administrator may still edit the rule, so it
 * may not be cached as an administrative exclusion; but it is also not
 * satisfiable by anything the learner can do, so it leaves the completion
 * denominator. Without the first the never cache goes stale on a clock tick;
 * without the second an instructor closing one assignment on 31 March makes
 * an all-lessons course permanently uncompletable for every learner enrolled
 * in it from 1 April, with no error anywhere and the course stalled at 96
 * percent.
 *
 * The timezone is attached to the instants rather than baked into them: the
 * API returns the instant as UTC plus the zone it was authored in, and the
 * client renders the viewer's local time with the authoritative time
 * alongside when the two differ. A single pre-localised string is not enough,
 * because the learner and the run can disagree and both readings are true.
 */
final class DateCondition implements
    AvailabilityConditionInterface,
    ConditionMetadataInterface,
    BatchEvaluatesItems,
    NamesOpensAt,
    DeclaresExhaustion,
    SupportsPayloadVersions,
    ValidatesPayload
{
    use EvaluatesManyByEvaluate;

    public const KEY = 'date';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.availability.condition.date';
    }

    public function group(): string
    {
        return ConditionMetadataInterface::GROUP_SCHEDULE;
    }

    public function order(): int
    {
        return 10;
    }

    public function advisory(): bool
    {
        return false;
    }

    /**
     * Automation is declared: a window is the same fact wherever it is
     * evaluated, and bounding a scheduled rule to a date range is the first
     * thing an author reaches for. It is the one leaf whose meaning does not
     * change between a gate read on a page and a test read by a scheduler,
     * because it reads the clock and the anchor and nothing about the run.
     */
    /**
     * The five surfaces, plus redemption gating, and the last one is a
     * decision rather than a sweep.
     *
     * A validity window is exactly what a coupon needs and it is the one
     * shipped condition that reads only the clock: no enrolment, no course,
     * no subject and no cart. So it can honour the redemption surface at full
     * function, which is what gives that surface a working free provider
     * rather than a vocabulary entry nothing reaches. The four other free
     * conditions exclude it, each with its own reason on its own
     * applicability().
     */
    public function applicability(): array
    {
        return [
            AvailabilitySurface::ContentGating,
            AvailabilitySurface::NotificationRule,
            AvailabilitySurface::AwardCriteria,
            AvailabilitySurface::EnrolmentGating,
            AvailabilitySurface::Automation,
        ];
    }

    public function currentShape(): int
    {
        return 1;
    }

    public function supportedPayloadVersions(): array
    {
        return [1];
    }

    public function upgrade(array $payload, int $from, UpgradeContext $context): array
    {
        return $payload;
    }

    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'from' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'until' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'tz' => ['type' => 'string'],
            ],
            'anyOf' => [
                ['required' => ['from']],
                ['required' => ['until']],
            ],
            'additionalProperties' => false,
        ];
    }

    public function evaluate(array $payload, bool $negated, AvailabilityContext $context): AvailabilityOutcome
    {
        $from = AnchorInstant::fromPayload($payload, 'from');
        $until = AnchorInstant::fromPayload($payload, 'until');
        $timezone = $this->timezoneOf($payload, $context);
        $now = $context->now;

        if ($from === null && $until === null) {
            return AvailabilityOutcome::blocked('availability.date.unconfigured');
        }

        $inWindow = ($from === null || $now >= $from) && ($until === null || $now < $until);

        if (!$negated) {
            if ($inWindow) {
                return AvailabilityOutcome::available();
            }

            if ($from !== null && $now < $from) {
                return AvailabilityOutcome::blocked(
                    'availability.date.from',
                    ['from' => $from->format(\DateTimeInterface::ATOM), 'tz' => $timezone],
                    $from,
                    $timezone,
                );
            }

            // Past the closing edge. Time-like, so never is forbidden;
            // unsatisfiable, so it leaves the denominator.
            return AvailabilityOutcome::blocked(
                'availability.date.until',
                ['until' => $until?->format(\DateTimeInterface::ATOM), 'tz' => $timezone],
                null,
                null,
                true,
            );
        }

        if (!$inWindow) {
            return AvailabilityOutcome::available();
        }

        if ($until !== null) {
            return AvailabilityOutcome::blocked(
                'availability.date.not_until',
                ['until' => $until->format(\DateTimeInterface::ATOM), 'tz' => $timezone],
                $until,
                $timezone,
            );
        }

        // An open-ended window the clock has entered: it never leaves it.
        return AvailabilityOutcome::blocked(
            'availability.date.not_from',
            ['from' => $from?->format(\DateTimeInterface::ATOM), 'tz' => $timezone],
            null,
            null,
            true,
        );
    }

    public function describe(array $payload, bool $negated, DescriptionContext $context): AvailabilityReason
    {
        $from = AnchorInstant::fromPayload($payload, 'from');
        $until = AnchorInstant::fromPayload($payload, 'until');
        $timezone = \is_string($payload['tz'] ?? null) ? (string) $payload['tz'] : 'UTC';

        $parameters = [
            'from' => $from?->format(\DateTimeInterface::ATOM),
            'until' => $until?->format(\DateTimeInterface::ATOM),
            'tz' => $timezone,
        ];

        if ($negated) {
            return new AvailabilityReason(
                $until !== null ? 'availability.date.not_until' : 'availability.date.not_from',
                $parameters,
            );
        }

        return new AvailabilityReason(
            $from !== null ? 'availability.date.from' : 'availability.date.until',
            $parameters,
        );
    }

    public function earliestOpensAt(array $payload, bool $negated, AvailabilityContext $context): ?\DateTimeImmutable
    {
        $outcome = $this->evaluate($payload, $negated, $context);

        return $outcome->opensAt;
    }

    public function validatePayload(array $payload, PayloadValidationContext $context): PayloadValidationResult
    {
        $from = AnchorInstant::fromPayload($payload, 'from');
        $until = AnchorInstant::fromPayload($payload, 'until');

        if ($from === null && $until === null) {
            return PayloadValidationResult::error('availability.date.range_required');
        }

        if ($from !== null && $until !== null && $until <= $from) {
            return PayloadValidationResult::error('availability.date.range_impossible', [
                'from' => $from->format(\DateTimeInterface::ATOM),
                'until' => $until->format(\DateTimeInterface::ATOM),
            ]);
        }

        $timezone = $payload['tz'] ?? null;

        if (\is_string($timezone) && $timezone !== '' && !\in_array($timezone, timezone_identifiers_list(), true)) {
            return PayloadValidationResult::error('availability.date.unknown_timezone', ['tz' => $timezone]);
        }

        return PayloadValidationResult::valid();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function timezoneOf(array $payload, AvailabilityContext $context): string
    {
        $timezone = $payload['tz'] ?? null;

        return \is_string($timezone) && $timezone !== '' ? $timezone : $context->timezone();
    }
}
