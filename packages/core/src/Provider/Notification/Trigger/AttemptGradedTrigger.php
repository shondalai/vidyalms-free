<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Notification\Trigger;

use Shondalai\VidyaLMS\Core\Domain\Events\AttemptGraded;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Registry\Context\TriggerContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;

/**
 * An attempt was graded.
 *
 * One of the two mails the shipped design copy already promises and the core
 * did not send: the partly-pending quiz result says the final result will
 * appear on screen and by email once it is marked, and shipping copy that
 * promises mail the product does not send is a defect.
 */
final class AttemptGradedTrigger extends AbstractEventTrigger
{
    public const KEY = 'vidyalms.mail.attempt_graded';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.trigger.attempt_graded';
    }

    public function categoryKey(): string
    {
        return 'assessment';
    }

    public function messageClass(): NotificationClass
    {
        return NotificationClass::Transactional;
    }

    public function defaultAudienceKey(): string
    {
        return 'learner';
    }

    public function defaultTemplateKey(): string
    {
        return self::KEY;
    }

    public function eventClasses(): array
    {
        return [AttemptGraded::class];
    }

    public function declaredPlaceholders(): array
    {
        return array_merge(parent::declaredPlaceholders(), [
            'course.title',
            'quiz.percentage',
            'quiz.passed',
        ]);
    }

    public function parametersFor(DomainEvent $event, TriggerContext $context): ?array
    {
        if (!$event instanceof AttemptGraded) {
            return null;
        }

        $quiz = $context->database->selectOne(Query::from('quizzes')
            ->select(['show_results', 'review_visibility'])->where('id', '=', $event->quizId));
        $review = $quiz['review_visibility'] ?? null;
        $review = \is_string($review) ? json_decode($review, true) : $review;
        // Email and in-app notifications have no authenticated review phase.
        // Withheld results remain available only through the review endpoint.
        if ($quiz === null || ($quiz['show_results'] ?? 'never') === 'never' || !empty($review)) {
            return null;
        }

        return $this->learnerParameters($event->userId, $event->courseId, $context) + [
            'attempt_id' => $event->attemptId,
            'quiz_id' => $event->quizId,
            'lesson_id' => $event->lessonId,
            'enrolment_id' => $event->enrolmentId,
            'percentage' => $event->percentage,
            'passed' => $event->passed,
            'recipient_user_id' => $event->userId,
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
        return ['user', 'quiz'];
    }

    public function subjectFor(DomainEvent $event): array
    {
        if (!$event instanceof AttemptGraded) {
            return [null, null];
        }

        return ['quiz', $event->quizId];
    }
}
