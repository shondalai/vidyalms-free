<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ReportDelivery;

use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationDigest;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationJobs;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueuedNotification;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueueState;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationQueueRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationTemplateRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryContext;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\DeliveryResult;
use Shondalai\VidyaLMS\Core\Registry\ReportDelivery\ReportDeliveryInterface;

/**
 * Enqueues a notification carrying a signed expiring storage URL.
 *
 * **A link and never an attachment.** Revoking somebody's access should revoke
 * the report too, and a link does that while an attachment sits in an inbox
 * indefinitely. Pro's `email-attachment` is an explicit opt-in for the sites
 * that accept that trade; the free provider does not make it for them.
 *
 * The design wrote this provider's free behaviour as a documented degraded
 * mode, because at the time nothing in `packages/core/src` referenced
 * `notification_queue` and there was no enqueue use case. The notification
 * engine has since landed, so it runs at full function: a real queue row, in
 * the pending state, addressed to the run's requester on the email channel.
 *
 * It writes the row directly through the queue repository rather than through
 * {@see \Shondalai\VidyaLMS\Core\Application\Notification\NotificationEnqueue},
 * on the same terms `forAutomationStep` already establishes: this occurrence
 * has no `notification_rules` row behind it, the audience is one person the
 * caller already resolved, and the channel is decided by the delivery rather
 * than by a rule. The producer-qualified dedupe digest is what keeps the two
 * producers apart, so a report run id can never be read as a rule id.
 *
 * The template is seeded on first use rather than at boot, exactly as the
 * default certificate template is: a site that never exports a report never
 * writes the row. It is seeded under its own template key and **no trigger
 * provider is registered for it**, because this occurrence is not rule driven
 * and a trigger on the rules screen that no rule can usefully fire would be a
 * control that does nothing.
 */
final class NotificationLinkDelivery implements ReportDeliveryInterface
{
    public const KEY = 'notification-link';

    /** Not a `vidyalms.trigger.*` key, because nothing triggers it. */
    public const TRIGGER_KEY = 'vidyalms.report.delivery';

    public const TEMPLATE_KEY = 'vidyalms.report.ready';

    public const CATEGORY_KEY = 'vidyalms.category.reports';

    public function __construct(
        private readonly SqlNotificationQueueRepository $queue,
        private readonly SqlNotificationTemplateRepository $templates,
        private readonly ClockPort $clock,
        private readonly TranslatorPort $translator,
        private readonly string $locale = 'en-GB',
        private readonly ?NotificationJobs $jobs = null,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.report.delivery.notification_link');
    }

    public function deliver(ReportRun $run, DeliveryContext $context): DeliveryResult
    {
        // A run that found nothing and was asked to say so has no artefact by
        // design, and refusing it would make the schedule's second
        // empty-report outcome unreachable on the free build. It is the one
        // case where a null URL is the message rather than the absence of one:
        // the recipient is told the report ran and found nothing, which is
        // exactly what "send without the file" means.
        $emptyRun = $run->state === ReportRun::STATE_EMPTY && $run->rowCount === 0;

        if ($context->signedUrl === null && !$emptyRun) {
            // The expensive half already succeeded and the artefact is on the
            // run record, so this is a delivery failure and never a run
            // failure. The direction is published for this registry.
            return DeliveryResult::failed('report_artefact_has_no_signed_url');
        }

        $recipients = $context->recipientUserIds === [] ? [$run->requestedBy] : $context->recipientUserIds;
        $this->seedTemplate();
        $enqueued = [];

        foreach ($recipients as $recipient) {
            if ($recipient < 1) {
                continue;
            }

            $row = $this->row($run, $context, $recipient);
            $id = $this->queue->enqueue($row);

            if ($id !== null) {
                $enqueued[] = (string) $id;
                $this->jobs?->queueSend($id, $row->dedupeKey, $row->runAt);
            }
        }

        if ($enqueued === []) {
            // Every occurrence was already enqueued, which is the replay
            // answer and not an error: the recipient already has the link.
            return DeliveryResult::delivered(null);
        }

        return DeliveryResult::delivered(implode(',', $enqueued));
    }

    private function row(ReportRun $run, DeliveryContext $context, int $recipient): QueuedNotification
    {
        // One occurrence per run and recipient, so a redelivery of one run is a
        // no-op and two runs of one schedule are two messages.
        $occurrence = 'report-run:' . ($run->id ?? 0) . ':u' . $recipient;

        return new QueuedNotification(
            null,
            null,
            self::TRIGGER_KEY,
            $context->categoryKey,
            NotificationClass::Transactional,
            self::TEMPLATE_KEY,
            $occurrence,
            $occurrence,
            NotificationDigest::forDelivery(
                NotificationDigest::notificationRuleRef(null),
                self::TRIGGER_KEY,
                $occurrence,
                self::TEMPLATE_KEY,
                'email',
                $recipient,
                $occurrence,
            ),
            $this->clock->now(),
            QueueState::Pending,
            $recipient,
            'email',
            'report_run',
            $run->id,
            [
                'report.key' => $run->reportKey,
                'report.format' => $run->formatKey,
                'report.row_count' => $run->rowCount,
                'report.url' => $context->signedUrl,
                'report.url_expires_at' => $context->urlExpiresAt?->format(DATE_ATOM),
                'report.expires_at' => $run->expiresAt->format(DATE_ATOM),
                '_report_message_subject' => $context->messageSubject,
                '_report_message_body' => $context->messageBody,
            ],
        );
    }

    /**
     * Seeds the template once, and only when the key has none, so an author's
     * edit is never overwritten.
     */
    private function seedTemplate(): void
    {
        $this->templates->seed(self::TEMPLATE_KEY, $this->locale, 'email', [
            'subject' => $this->translator->translate('vidyalms.report.ready.subject'),
            'body_text' => $this->translator->translate('vidyalms.report.ready.body'),
            'state' => SqlNotificationTemplateRepository::STATE_PUBLISHED,
        ]);
    }
}
