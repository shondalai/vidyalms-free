<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Domain\Events\SubmissionReceived;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Registry\Context\TriggerContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;

/**
 * An assignment was submitted.
 *
 * The other mail the shipped design copy already promises and the core did
 * not send: the instructor gradebook's empty state says new submissions
 * appear on screen and by email.
 *
 * Its default audience is the course instructors rather than the learner,
 * which is why the audience registry exists: a free-text recipient list in an
 * admin form is an unauthenticated mail relay.
 */
final class SubmissionReceivedTrigger extends AbstractEventTrigger
{
    public const KEY = 'vidyalms.mail.submission_received';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.trigger.submission_received';
    }

    public function categoryKey(): string
    {
        return 'assessment';
    }

    public function messageClass(): NotificationClass
    {
        return NotificationClass::Operational;
    }

    public function defaultAudienceKey(): string
    {
        return 'course_instructors';
    }

    public function defaultTemplateKey(): string
    {
        return self::KEY;
    }

    public function eventClasses(): array
    {
        return [SubmissionReceived::class];
    }

    public function declaredPlaceholders(): array
    {
        return array_merge(parent::declaredPlaceholders(), ['course.title', 'assignment.title']);
    }

    public function parametersFor(DomainEvent $event, TriggerContext $context): ?array
    {
        if (!$event instanceof SubmissionReceived) {
            return null;
        }

        return $this->learnerParameters($event->userId, $event->courseId, $context) + [
            'submission_id' => $event->submissionId,
            'assignment_id' => $event->assignmentId,
            'lesson_id' => $event->lessonId,
            'enrolment_id' => $event->enrolmentId,
        ];
    }

    /**
     * What an occurrence of this trigger can be about: the recipient is the
     * learner and the subject is the thing they did it to.
     *
     * @return list<string>
     */
    public function subjectTypes(): array
    {
        return ['user', 'assignment'];
    }

    public function subjectFor(DomainEvent $event): array
    {
        if (!$event instanceof SubmissionReceived) {
            return [null, null];
        }

        return ['assignment', $event->assignmentId];
    }
}
