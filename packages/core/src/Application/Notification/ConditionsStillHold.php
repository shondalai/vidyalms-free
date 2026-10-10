<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityComposer;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\SubjectTree;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTree;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * Whether a rule's condition tree still holds at the send moment.
 *
 * It is the same engine and the same grammar the content gate uses, on the
 * notification surface, which is what makes a third-party condition usable in
 * a notification rule the day it registers rather than after a second rule
 * language is invented.
 *
 * Because this evaluation happens with no item in hand, the nullable course
 * and subject members of the availability context are load-bearing at send
 * rather than merely tolerated.
 */
final class ConditionsStillHold
{
    public function __construct(
        private readonly AvailabilityComposer $composer,
        private readonly DatabasePort $database,
    ) {
    }

    public function hold(AvailabilityTree $tree, AvailabilityContext $context, ?int $courseId): bool
    {
        if ($tree->isEmpty()) {
            return true;
        }

        $course = null;
        $enrolment = null;

        if ($courseId !== null) {
            $course = $this->database->selectOne(Query::from('courses')->where('id', '=', $courseId));

            $enrolment = $this->database->selectOne(
                Query::from('enrolments')
                    ->where('course_id', '=', $courseId)
                    ->where('user_id', '=', $context->principal->userId),
            );
        }

        $resolved = AvailabilityContext::create(
            $context->principal,
            $context->surface,
            $context->now,
            $enrolment,
            $course,
            null,
            $context->timezoneChain,
        );

        $decisions = $this->composer->evaluateSet(['rule' => new SubjectTree($tree, null)], $resolved);

        return ($decisions['rule'] ?? null)?->isAvailable() ?? true;
    }
}
