<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor;

use Shondalai\VidyaLMS\Core\Registry\Context\AnchorContext;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\DeclaresAnchorParams;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorInterface;

/**
 * The instant in the leaf's own payload.
 *
 * This is the first of the three defensible answers to late enrolment: a
 * fixed date already in the past unlocks immediately. The choice is per rule
 * and never global, which is why a single course can mix all three.
 */
final class AbsoluteAnchor implements ScheduleAnchorInterface, DeclaresAnchorParams
{
    public const KEY = 'absolute';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.anchor.absolute';
    }

    /**
     * The payload this anchor reads, published rather than left to an
     * author to discover from the provider's source. The catalogue used to
     * answer an empty schema for every anchor, which is what "takes
     * nothing" looks like and was wrong for four of the five.
     *
     * @return array<string, mixed>
     */
    public function paramsSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['at'],
            'additionalProperties' => false,
            'properties' => [
                'at' => ['type' => 'string', 'format' => 'date-time'],
                'from' => ['type' => 'string', 'format' => 'date-time'],
                'tz' => ['type' => 'string'],
            ],
        ];
    }

    public function resolve(AnchorContext $context): ?\DateTimeImmutable
    {
        return AnchorInstant::fromPayload($context->payload, 'at')
            ?? AnchorInstant::fromPayload($context->payload, 'from');
    }

    public function timezoneFor(AnchorContext $context): string
    {
        $timezone = $context->payload['tz'] ?? null;

        return \is_string($timezone) && $timezone !== '' ? $timezone : $context->resolvedTimezone();
    }
}
