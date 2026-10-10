<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor;

use Shondalai\VidyaLMS\Core\Registry\Context\AnchorContext;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\DeclaresAnchorParams;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorInterface;

/**
 * This learner's own enrolment instant.
 *
 * The second defensible answer to late enrolment: the offset restarts from
 * this learner's own enrolment, so an evergreen course works. A design
 * supporting only this and the absolute anchor cannot run a cohort, which is
 * exactly why one well-known platform's core cannot and another can.
 */
final class EnrolmentAnchor implements ScheduleAnchorInterface, DeclaresAnchorParams
{
    public const KEY = 'enrolment.enrolled_at';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.anchor.enrolment';
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
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => []];
    }

    public function resolve(AnchorContext $context): ?\DateTimeImmutable
    {
        return AnchorInstant::fromRow($context->enrolment, 'enrolled_at')
            ?? AnchorInstant::fromRow($context->enrolment, 'created_at');
    }

    public function timezoneFor(AnchorContext $context): string
    {
        return $context->resolvedTimezone();
    }
}
