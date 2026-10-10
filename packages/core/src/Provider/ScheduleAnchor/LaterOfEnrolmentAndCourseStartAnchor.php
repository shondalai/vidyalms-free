<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor;

use Shondalai\VidyaLMS\Core\Registry\Context\AnchorContext;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\DeclaresAnchorParams;
use Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor\ScheduleAnchorInterface;

/**
 * The later of the enrolment instant and the course start.
 *
 * It is a composed anchor rather than a special case welded into an offset
 * provider. Two well-known platforms arrived at this same rule
 * independently, and having it as one registration is what stops the logic
 * being copied into the relative-date provider, the cohort resolver and the
 * notification schedule.
 */
final class LaterOfEnrolmentAndCourseStartAnchor implements ScheduleAnchorInterface, DeclaresAnchorParams
{
    public const KEY = 'later_of_enrolment_and_course_start';

    public function __construct(
        private readonly EnrolmentAnchor $enrolment = new EnrolmentAnchor(),
        private readonly CourseStartAnchor $courseStart = new CourseStartAnchor(),
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.anchor.later_of_enrolment_and_course_start';
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
        $enrolled = $this->enrolment->resolve($context);
        $started = $this->courseStart->resolve($context);

        if ($enrolled === null) {
            return $started;
        }

        if ($started === null) {
            return $enrolled;
        }

        return $enrolled > $started ? $enrolled : $started;
    }

    public function timezoneFor(AnchorContext $context): string
    {
        return $this->courseStart->timezoneFor($context);
    }
}
