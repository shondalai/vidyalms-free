<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor;

use Shondalai\VidyaLMS\Core\Registry\Context\AnchorContext;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\DeclaresAnchorParams;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorInterface;

/**
 * The course's own start instant, from courses.starts_at.
 *
 * The course's timezone is the one its wall-clock dates were authored in, so
 * this anchor answers that rather than the site's: resolving "09:00 on 3
 * February" against the wrong zone is a documented production bug class and
 * not a hypothetical.
 */
final class CourseStartAnchor implements ScheduleAnchorInterface, DeclaresAnchorParams
{
    public const KEY = 'course.starts_at';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.anchor.course_start';
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
        return AnchorInstant::fromRow($context->course, 'starts_at');
    }

    public function timezoneFor(AnchorContext $context): string
    {
        return $context->resolvedTimezone();
    }
}
