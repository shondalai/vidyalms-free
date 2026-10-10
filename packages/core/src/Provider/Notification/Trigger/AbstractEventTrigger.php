<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\TriggerContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\DeclaresNotificationTemplateBlueprint;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\DeclaresSubjectTypes;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\EventTriggerInterface;
use Shondalai\VidyaLMS\Core\Registry\Notification\Trigger\NotificationTemplateBlueprint;

/**
 * What the six free event triggers share: the metadata a rule reads, the
 * placeholder vocabulary every message can use, and two lookups that would
 * otherwise be copied six times.
 *
 * The parameters carry ids from the event, the learner's display name from
 * the people port, and the course title read-only from the curriculum
 * context's own table, which a communication provider may read but never
 * write.
 */
abstract class AbstractEventTrigger implements
    EventTriggerInterface,
    DeclaresNotificationTemplateBlueprint,
    DeclaresSubjectTypes
{
    /** Placeholders every message can use, whatever the trigger. */
    protected const COMMON_PLACEHOLDERS = [
        'user.name',
        'user.first_name',
        'user.email',
        'site.name',
        'site.url',
    ];

    public function kind(): string
    {
        return self::KIND_EVENT;
    }

    public function declaredPlaceholders(): array
    {
        return self::COMMON_PLACEHOLDERS;
    }

    public function notificationTemplateBlueprint(): NotificationTemplateBlueprint
    {
        return CoreNotificationBlueprints::for($this->defaultTemplateKey(), $this->messageClass());
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * The identity of the occurrence, and the few values a message is
     * entitled to freeze.
     *
     * **The address is never one of them.** The queue row stores intent and a
     * terminal row keeps its identity forever, so an email address written
     * here grew a second plaintext copy of the mailing list across
     * notification_queue and notification_digest_items, beside a suppression
     * table that stores an address digest precisely so that it is not one. It
     * was not load-bearing either: the sender re-resolves the recipient from
     * the people port immediately before handing to a channel, and the user
     * token resolver falls back to the same port when the parameter is
     * absent.
     *
     * The display name and the course title stay, and the rule is that a
     * frozen key is named here and nowhere else: they are what the message
     * says about the moment it was written, they are not addresses, and a
     * message about a course renamed six months later should still read the
     * way it was sent.
     *
     * @return array<string, mixed>
     */
    protected function learnerParameters(int $userId, int $courseId, TriggerContext $context): array
    {
        $user = $context->users->findById($userId);

        return [
            'user_id' => $userId,
            'course_id' => $courseId,
            'learner_name' => $user->name ?? '',
            'course_title' => $this->courseTitle($courseId, $context),
        ];
    }

    protected function courseTitle(int $courseId, TriggerContext $context): string
    {
        $row = $context->database->selectOne(
            Query::from('courses')->select(['title'])->where('id', '=', $courseId),
        );

        $title = $row['title'] ?? null;

        return \is_string($title) ? $title : '';
    }

    /**
     * @return array{0: string|null, 1: int|null}
     */
    public function subjectFor(DomainEvent $event): array
    {
        $payload = $event->payload();
        $courseId = $payload['course_id'] ?? null;

        return ['course', \is_numeric($courseId) ? (int) $courseId : null];
    }

    /**
     * What an occurrence of this trigger can be about.
     *
     * Both, and that is not a hedge: the subject is the course and the
     * recipient is the learner, so an action that acts for a person and an
     * action that acts on a course are each applicable behind it. The
     * automation catalogue publishes this and the save-time applicability
     * check reads it, which used to be impossible because the catalogue
     * hardcoded an empty list for every trigger.
     *
     * @return list<string>
     */
    public function subjectTypes(): array
    {
        return ['user', 'course'];
    }
}
