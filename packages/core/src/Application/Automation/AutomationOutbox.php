<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

/**
 * The automation half of the transactional outbox, held behind a settable
 * reference because the engine is built after the outbox that needs it.
 *
 * TransactionalOutbox is constructed early, before the automation engine
 * exists, and every trigger-emitting use case already holds it. The automation
 * intent has to be written inside the same transaction as the notification
 * intent, for the same reason: the event bus wraps every listener in a try and
 * logs, so an intent written from a listener commits the business state and
 * loses the row.
 *
 * A settable holder rather than a closure over the kernel, because a closure
 * reading an uninitialised readonly property is exactly the kind of ordering
 * dependency that reads as working until the order changes. This object is
 * empty until the engine fills it, and an outbox that commits before it is
 * filled writes no automation intent and says so by answering null.
 */
final class AutomationOutbox
{
    private ?OpenAutomationRuns $runs = null;

    private ?AutomationJobs $jobs = null;

    public function bind(OpenAutomationRuns $runs, AutomationJobs $jobs): void
    {
        $this->runs = $runs;
        $this->jobs = $jobs;
    }

    public function runs(): ?OpenAutomationRuns
    {
        return $this->runs;
    }

    public function jobs(): ?AutomationJobs
    {
        return $this->jobs;
    }
}
