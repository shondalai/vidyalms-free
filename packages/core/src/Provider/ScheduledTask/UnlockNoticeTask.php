<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityEngine;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Domain\Events\ContentUnlocked;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * The scheduled unlock notice.
 *
 * It re-evaluates and may emit nothing. The scheduler's punctuality is not a
 * guarantee anything here relies on: if the composed outcome is not available
 * when the task actually runs, the handler returns having done nothing, and
 * that invariant lives here and in the test matrix rather than in the
 * scheduler's docblock.
 *
 * It emits the event and nothing else. Handing a notification to the queue
 * directly would skip the trigger, audience and policy fold, and therefore
 * skip consent, suppression, quiet hours and the frequency cap on a message
 * this design elsewhere treats as subject to legal obligation, and it would
 * write a row with no rule, no category and no class.
 */
final class UnlockNoticeTask implements ScheduledTaskHandlerInterface
{
    public const TYPE = 'vidyalms.availability.unlock';

    public function __construct(
        private readonly AvailabilityEngine $availability,
        private readonly EventBus $events,
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function key(): string
    {
        return self::TYPE;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $enrolmentId = $run->int('enrolment_id');
        $subjectType = $run->string('subject_type');
        $subjectId = $run->int('subject_id');

        if ($enrolmentId < 1 || $subjectId < 1 || !GateSubject::isKnownType($subjectType)) {
            return 'Unlock notice skipped: the task payload names no subject.';
        }

        $enrolment = $this->database->selectOne(Query::from('enrolments')->where('id', '=', $enrolmentId));

        if ($enrolment === null) {
            return 'Unlock notice skipped: the enrolment is gone.';
        }

        $courseId = (int) $enrolment['course_id'];
        $subject = new GateSubject($subjectType, $subjectId);
        $principal = Principal::user((int) $enrolment['user_id']);
        $decision = $this->availability->forSubject($principal, $subject, $courseId, $enrolment);

        if (!$decision->isAvailable()) {
            return 'Unlock notice withheld: the subject is still blocked.';
        }

        $this->events->dispatch(new ContentUnlocked(
            $this->clock->now(),
            $enrolmentId,
            $courseId,
            (int) $enrolment['user_id'],
            $subjectType,
            $subjectId,
        ));

        return sprintf('Unlock notice emitted for %s %d.', $subjectType, $subjectId);
    }
}
